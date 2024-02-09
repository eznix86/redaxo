<?php

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
// Opt out of google FLoC
header('Permissions-Policy: interest-cohort=()');

// assets which are passed with a cachebuster will be cached very long,
// as we assume their url will change when the underlying content changes
if (rex_get('asset') && rex_get('buster')) {
    /** @psalm-taint-escape file */ // it is not escaped here, but it is validated below via the realpath
    $assetFile = rex_get('asset');

    // relative to the assets-root
    if (str_starts_with($assetFile, '/assets/')) {
        $assetFile = '..' . $assetFile;
    }

    $fullPath = realpath($assetFile);
    $assetDir = rex_path::assets();

    if (!str_starts_with($fullPath, $assetDir)) {
        throw new Exception('Assets can only be streamed from within the assets folder. "' . $fullPath . '" is not within "' . $assetDir . '"');
    }

    $ext = rex_file::extension($assetFile);
    if ('js' === $ext) {
        $js = rex_file::require($assetFile);

        $js = preg_replace('@^//# sourceMappingURL=.*$@m', '', $js);

        rex_response::sendCacheControl('max-age=31536000, immutable');
        rex_response::sendContent($js, 'application/javascript');
    } elseif ('css' === $ext) {
        $styles = rex_file::require($assetFile);

        // If we are in a directory off the root, add a relative path here back to the root, like "../"
        // get the public path to this file, plus the baseurl
        $relativeroot = '';
        $pubroot = dirname($_SERVER['PHP_SELF']) . '/' . $relativeroot;

        $prefix = $pubroot . dirname($assetFile) . '/';
        $styles = preg_replace('/(url\(["\']?)([^\/"\'])([^\:\)]+["\']?\))/i', '$1' . $prefix . '$2$3', $styles);

        rex_response::sendCacheControl('max-age=31536000, immutable');
        rex_response::sendContent($styles, 'text/css');
    } else {
        rex_response::setStatus(rex_response::HTTP_NOT_FOUND);
        rex_response::sendContent('file not found');
    }
    exit;
}

// ----- verfuegbare seiten
$pages = [];

// ----------------- SETUP
if (rex::isSetup()) {
    // ----------------- SET SETUP LANG
    $requestLang = rex_request('lang', 'string', rex::getProperty('lang'));
    if (in_array($requestLang, rex_i18n::getLocales())) {
        rex::setProperty('lang', $requestLang);
    } else {
        rex::setProperty('lang', 'en_gb');
    }

    rex_i18n::setLocale(rex::getProperty('lang'));

    $pages['setup'] = rex_be_controller::getSetupPage();
    rex_be_controller::setCurrentPage('setup');
} else {
    // ----------------- CREATE LANG OBJ
    rex_i18n::setLocale(rex::getProperty('lang'));

    // ---- prepare login
    $login = new rex_backend_login();
    rex::setProperty('login', $login);

    $passkey = rex_post('rex_user_passkey', 'string', null);
    $rexUserLogin = rex_post('rex_user_login', 'string');
    $rexUserPsw = rex_post('rex_user_psw', 'string');
    $rexUserStayLoggedIn = rex_post('rex_user_stay_logged_in', 'boolean', false);

    if (rex_get('rex_logout', 'boolean') && rex_csrf_token::factory('backend_logout')->isValid()) {
        $login->setLogout(true);
        $login->checkLogin();
        rex_csrf_token::removeAll();

        $userAgent = rex_server('HTTP_USER_AGENT');
        $advertisedChrome = preg_match('/(Chrome|CriOS)\//i', $userAgent);
        $nonChrome = preg_match('/(Aviator|ChromePlus|coc_|Dragon|Edge|Flock|Iron|Kinza|Maxthon|MxNitro|Nichrome|OPR|Perk|Rockmelt|Seznam|Sleipnir|Spark|UBrowser|Vivaldi|WebExplorer|YaBrowser)/i', $userAgent);
        if ($advertisedChrome && !$nonChrome) {
            // Browser is likely Google Chrome which currently seems to be super slow when clearing 'cache' from site data
            // https://bugs.chromium.org/p/chromium/issues/detail?id=762417
            rex_response::setHeader('Clear-Site-Data', '"storage", "executionContexts"');
        } else {
            rex_response::setHeader('Clear-Site-Data', '"cache", "storage", "executionContexts"');
        }

        // Currently browsers like Safari do not support the header Clear-Site-Data.
        // we dont kill/regenerate the session so e.g. the frontend will not get logged out
        rex_request::clearSession();

        // is necessary for login after logout
        // and without the redirect, the csrf token would be invalid
        rex_response::sendRedirect(rex_url::backendController(['rex_logged_out' => 1]));
    }

    $rexUserLoginmessage = '';

    if (($rexUserLogin || $passkey) && !rex_csrf_token::factory('backend_login')->isValid()) {
        $loginCheck = rex_i18n::msg('csrf_token_invalid');
    } else {
        // the server side encryption of pw is only required
        // when not already encrypted by client using javascript
        $login->setLogin($rexUserLogin, $rexUserPsw, rex_post('javascript', 'boolean'));
        $login->setPasskey('' === $passkey ? null : $passkey);
        $login->setStayLoggedIn($rexUserStayLoggedIn);
        $loginCheck = $login->checkLogin();
    }

    if (true !== $loginCheck) {
        if (rex_request::isXmlHttpRequest()) {
            rex_response::setStatus(rex_response::HTTP_UNAUTHORIZED);
        }

        // login failed
        $rexUserLoginmessage = $login->getMessage();

        // Fehlermeldung von der Datenbank
        if (is_string($loginCheck)) {
            $rexUserLoginmessage = $loginCheck;
        }

        $pages['login'] = rex_be_controller::getLoginPage();
        rex_be_controller::setCurrentPage('login');

        if ('login' !== rex_request('page', 'string', 'login')) {
            // clear in-browser data of a previous session with the same browser for security reasons.
            // a possible attacker should not be able to access cached data of a previous valid session on the same computer.
            // clearing "executionContext" or "cookies" would result in a endless loop.
            rex_response::setHeader('Clear-Site-Data', '"cache", "storage"');

            // Currently browsers like Safari do not support the header Clear-Site-Data.
            // we dont kill/regenerate the session so e.g. the frontend will not get logged out
            rex_request::clearSession();
        }
    } else {
        // Userspezifische Sprache einstellen
        $user = $login->getUser();
        $lang = $user->getLanguage();
        if ($lang && 'default' != $lang && $lang != rex::getProperty('lang')) {
            rex_i18n::setLocale($lang);
        }

        rex::setProperty('user', $user);

        // Safe Mode
        if ($user->isAdmin() && null !== ($safeMode = rex_get('safemode', 'boolean', null))) {
            if ($safeMode) {
                rex_set_session('safemode', true);
            } else {
                rex_unset_session('safemode');
                if (rex::getProperty('safemode')) {
                    $configFile = rex_path::coreData('config.yml');
                    $config = array_merge(
                        rex_file::getConfig(rex_path::core('default.config.yml')),
                        rex_file::getConfig($configFile),
                    );
                    $config['safemode'] = false;
                    rex_file::putConfig($configFile, $config);
                }
            }
        }
    }

    if ('' === $rexUserLoginmessage && rex_get('rex_logged_out', 'boolean')) {
        $rexUserLoginmessage = rex_i18n::msg('login_logged_out');
    }
}

rex_be_controller::setPages($pages);

// ----- Prepare Core Pages
if (rex::getUser()) {
    rex_be_controller::setCurrentPage(trim(rex_request('page', 'string')));
    rex_be_controller::appendLoggedInPages();

    if ('profile' !== rex_be_controller::getCurrentPage() && rex::getProperty('login')->requiresPasswordChange()) {
        rex_response::sendRedirect(rex_url::backendPage('profile'));
    }
}

rex_view::addJsFile(rex_url::coreAssets('jquery.min.js'), [rex_view::JS_IMMUTABLE => true]);
rex_view::addJsFile(rex_url::coreAssets('jquery-ui.custom.min.js'), [rex_view::JS_IMMUTABLE => true]);
rex_view::addJsFile(rex_url::coreAssets('jquery-pjax.min.js'), [rex_view::JS_IMMUTABLE => true]);
rex_view::addJsFile(rex_url::coreAssets('standard.js'), [rex_view::JS_IMMUTABLE => true]);
rex_view::addJsFile(rex_url::coreAssets('sha1.js'), [rex_view::JS_IMMUTABLE => true]);
rex_view::addJsFile(rex_url::coreAssets('clipboard-copy-element.js'), [rex_view::JS_IMMUTABLE => true]);

rex_view::setJsProperty('backend', true);
rex_view::setJsProperty('accesskeys', rex::getProperty('use_accesskeys'));
rex_view::setJsProperty('session_keep_alive', rex::getProperty('session_keep_alive', 0));
rex_view::setJsProperty('cookie_params', rex_login::getCookieParams());

rex_extension::register('BE_STYLE_SCSS_COMPILE', static function (rex_extension_point $ep) {
    $scssFiles = rex_extension::registerPoint(new rex_extension_point('BE_STYLE_SCSS_FILES', []));

    /** @var list<array{root_dir?: string, scss_files: string|list<string>, css_file: string, copy_dest?: string}> $subject */
    $subject = $ep->getSubject();
    $subject[] = [
        'root_dir' => rex_path::core('assets_files/scss/'),
        'scss_files' => array_merge($scssFiles, [rex_path::core('assets_files/scss/master.scss')]),
        'css_file' => rex_path::core('assets/css/styles.css'),
        'copy_dest' => rex_path::coreAssets('css/styles.css'),
    ];
    $subject[] = [
        'root_dir' => rex_path::core('assets_files/scss/'),
        'scss_files' => rex_path::core('assets_files/scss/redaxo.scss'),
        'css_file' => rex_path::core('assets/css/redaxo.css'),
        'copy_dest' => rex_path::coreAssets('css/redaxo.css'),
    ];
    return $subject;
});

rex_extension::register('PACKAGES_INCLUDED', static function () {
    if (rex::getUser() && rex::getConfig('be_style_compile')) {
        rex_be_style::compile();
    }
});

rex_view::addCssFile(rex_url::coreAssets('css/styles.css'));
rex_view::addCssFile(rex_url::coreAssets('css/bootstrap-select.min.css'));
rex_view::addJsFile(rex_url::coreAssets('js/bootstrap.js'), [rex_view::JS_IMMUTABLE => true]);
rex_view::addJsFile(rex_url::coreAssets('js/bootstrap-select.min.js'), [rex_view::JS_IMMUTABLE => true]);
$bootstrapSelectLang = [
    'de_de' => 'de_DE',
    'en_gb' => 'en_US',
    'es_es' => 'de_DE',
    'it_it' => 'it_IT',
    'nl_nl' => 'nl_NL',
    'pt_br' => 'pt_BR',
    'sv_se' => 'sv_SE',
][rex_i18n::getLocale()] ?? 'en_US';
rex_view::addJsFile(rex_url::coreAssets('js/bootstrap-select-defaults-' . $bootstrapSelectLang . '.min.js'), [rex_view::JS_IMMUTABLE => true]);
rex_view::addJsFile(rex_url::coreAssets('js/main.js'), [rex_view::JS_IMMUTABLE => true]);

rex_view::addCssFile(rex_url::coreAssets('css/redaxo.css'));
rex_view::addJsFile(rex_url::coreAssets('js/redaxo.js'), [rex_view::JS_IMMUTABLE => true]);

rex_extension::register('PAGE_HEADER', static function (rex_extension_point $ep) {
    $themeColor = rex::getConfig('be_style_labelcolor', '#4d99d3');

    $icons = [];
    $icons[] = '<link rel="apple-touch-icon" sizes="180x180" href="' . rex_url::coreAssets('icons/apple-touch-icon.png') . '">';
    $icons[] = '<link rel="icon" type="image/png" sizes="32x32" href="' . rex_url::coreAssets('icons/favicon-32x32.png') . '">';
    $icons[] = '<link rel="icon" type="image/png" sizes="16x16" href="' . rex_url::coreAssets('icons/favicon-16x16.png') . '">';
    $icons[] = '<link rel="manifest" href="' . rex_url::coreAssets('icons/site.webmanifest') . '">';
    $icons[] = '<link rel="mask-icon" href="' . rex_url::coreAssets('icons/safari-pinned-tab.svg') . '" color="' . rex_escape($themeColor) . '">';
    $icons[] = '<meta name="msapplication-TileColor" content="#2d89ef">';

    $icons = implode("\n    ", $icons);
    $ep->setSubject($icons . $ep->getSubject());
});

// add theme-information to js-variable rex as rex.theme
// (1) System-Settings (2) no systemforced mode: user-mode (3) fallback: "auto"
$user = rex::getUser();
$theme = (string) rex::getProperty('theme');
if ('' === $theme && $user) {
    $theme = (string) $user->getValue('theme');
}
rex_view::setJsProperty('theme', $theme ?: 'auto');

// ----- INCLUDE ADDONS
include_once rex_path::core('packages.php');

// ----- Prepare AddOn Pages
if (rex::getUser()) {
    rex_be_controller::appendPackagePages();
}

$pages = rex_extension::registerPoint(new rex_extension_point('PAGES_PREPARED', rex_be_controller::getPages()));
rex_be_controller::setPages($pages);

// Set Startpage
if ($user = rex::getUser()) {
    if (rex::getProperty('login')->requiresPasswordChange()) {
        // profile is available for everyone, no additional checks required
        rex_be_controller::setCurrentPage('profile');
    } elseif (!rex_be_controller::getCurrentPage()) {
        // trigger api functions before page permission check/redirection, if page param is not set.
        // the api function is responsible for checking permissions.
        rex_api_function::handleCall();
    }

    // --- page pruefen und benoetigte rechte checken
    rex_be_controller::checkPagePermissions($user);
}
$page = rex_be_controller::getCurrentPage();
rex_view::setJsProperty('page', $page);

// ----- EXTENSION POINT
// page variable validated
rex_extension::registerPoint(new rex_extension_point('PAGE_CHECKED', $page, ['pages' => $pages], true));

if (in_array($page, ['profile', 'login'], true)) {
    rex_view::addJsFile(rex_url::coreAssets('webauthn.js'), [rex_view::JS_IMMUTABLE => true]);
}

if ($page) {
    // trigger api functions after PAGE_CHECKED, if page param is set
    // the api function is responsible for checking permissions.
    rex_api_function::handleCall();
}

// include the requested backend page
rex_be_controller::includeCurrentPage();

// ----- caching end für output filter
$CONTENT = ob_get_clean();

// ----- inhalt ausgeben
rex_response::sendPage($CONTENT);
