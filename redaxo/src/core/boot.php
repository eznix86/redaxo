<?php

/**
 * REDAXO main boot file.
 *
 * @var array{HTDOCS_PATH: non-empty-string, BACKEND_FOLDER: non-empty-string, REDAXO: bool, LOAD_PAGE?: bool, PATH_PROVIDER?: object, URL_PROVIDER?: object} $REX
 *          HTDOCS_PATH    [Required] Relative path to htdocs directory
 *          BACKEND_FOLDER [Required] Name of backend folder
 *          REDAXO         [Required] Backend/Frontend flag
 *          LOAD_PAGE      [Optional] Wether the front controller should be loaded or not. Default value is false.
 *          PATH_PROVIDER  [Optional] Custom path provider
 *          URL_PROVIDER   [Optional] Custom url provider
 */

define('REX_MIN_PHP_VERSION', '8.1');

if (version_compare(PHP_VERSION, REX_MIN_PHP_VERSION) < 0) {
    echo 'Ooops, something went wrong!<br>';
    throw new Exception('PHP version >=' . REX_MIN_PHP_VERSION . ' needed!');
}

foreach (['HTDOCS_PATH', 'BACKEND_FOLDER', 'REDAXO'] as $key) {
    if (!isset($REX[$key])) {
        throw new Exception('Missing required global variable $REX[\'' . $key . "']");
    }
}

// start output buffering as early as possible, so we can be sure
// we can set http header whenever we want/need to
ob_start();
ob_implicit_flush(false);

if ('cli' !== PHP_SAPI) {
    // deactivate session cache limiter
    @session_cache_limiter('');
}

ini_set('session.use_strict_mode', '1');

ini_set('arg_separator.output', '&');
// disable html_errors to avoid html in exceptions and log files
if (ini_get('html_errors')) {
    ini_set('html_errors', '0');
}

require_once __DIR__ . '/lib/util/path.php';

if (isset($REX['PATH_PROVIDER']) && is_object($REX['PATH_PROVIDER'])) {
    /** @var rex_path_default_provider */
    $pathProvider = $REX['PATH_PROVIDER'];
} else {
    require_once __DIR__ . '/lib/util/path_default_provider.php';
    $pathProvider = new rex_path_default_provider($REX['HTDOCS_PATH'], $REX['BACKEND_FOLDER'], true);
}

rex_path::init($pathProvider);

require_once rex_path::core('lib/autoload.php');

// register core-classes as php-handlers
rex_autoload::register();
// add core base-classpath to autoloader
rex_autoload::addDirectory(rex_path::core('lib'));

// must be called after `rex_autoload::register()` to support symfony/polyfill-mbstring
mb_internal_encoding('UTF-8');

if (isset($REX['URL_PROVIDER']) && is_object($REX['URL_PROVIDER'])) {
    /** @var rex_path_default_provider */
    $urlProvider = $REX['URL_PROVIDER'];
} else {
    $urlProvider = new rex_path_default_provider($REX['HTDOCS_PATH'], $REX['BACKEND_FOLDER'], false);
}

rex_url::init($urlProvider);

// start timer at the very beginning
rex::setProperty('timer', new rex_timer($_SERVER['REQUEST_TIME_FLOAT'] ?? null));
// add backend flag to rex
rex::setProperty('redaxo', $REX['REDAXO']);
// add core lang directory to rex_i18n
rex_i18n::addDirectory(rex_path::core('lang'));
// add core base-fragmentpath to fragmentloader
rex_fragment::addDirectory(rex_path::core('fragments/'));

// ----------------- FUNCTIONS
require_once rex_path::core('functions/function_rex_escape.php');
require_once rex_path::core('functions/function_rex_globals.php');
require_once rex_path::core('functions/function_rex_other.php');

// ----------------- VERSION
rex::setProperty('version', '6.0.0-dev');

$cacheFile = rex_path::coreCache('config.yml.cache');
$configFile = rex_path::coreData('config.yml');

$cacheMtime = @filemtime($cacheFile);
if ($cacheMtime && $cacheMtime >= @filemtime($configFile)) {
    $config = rex_file::getCache($cacheFile);
} else {
    $config = array_merge(
        rex_file::getConfig(rex_path::core('default.config.yml')),
        rex_file::getConfig($configFile),
    );
    rex_file::putCache($cacheFile, $config);
}
/**
 * @var string $key
 * @var mixed $value
 */
foreach ($config as $key => $value) {
    if (in_array($key, ['fileperm', 'dirperm'])) {
        $value = octdec((string) $value);
    }
    rex::setProperty($key, $value);
}

date_default_timezone_set(rex::getProperty('timezone', 'Europe/Berlin'));

if ('cli' !== PHP_SAPI) {
    rex::setProperty('request', Symfony\Component\HttpFoundation\Request::createFromGlobals());
}

rex_error_handler::register();
rex_var_dumper::register();

// ----------------- REX PERMS

rex_complex_perm::register('clang', rex_clang_perm::class);

// ----- SET CLANG
if (!rex::isSetup()) {
    $clangId = rex_request('clang', 'int', rex_clang::getStartId());
    if (rex::isBackend() || rex_clang::exists($clangId)) {
        rex_clang::setCurrentId($clangId);
    }
}

// ----------------- HTTPS REDIRECT
if ('cli' !== PHP_SAPI && !rex::isSetup()) {
    if ((true === rex::getProperty('use_https') || rex::getEnvironment() === rex::getProperty('use_https')) && !rex_request::isHttps()) {
        rex_response::enforceHttps();
    }

    if (true === rex::getProperty('use_hsts') && rex_request::isHttps()) {
        rex_response::setHeader('Strict-Transport-Security', 'max-age=' . (int) rex::getProperty('hsts_max_age', 31536000)); // default 1 year
    }
}

rex_extension::register('SESSION_REGENERATED', [rex_backend_login::class, 'sessionRegenerated']);

$nexttime = rex::isSetup() || rex::getConsole() ? 0 : (int) rex::getConfig('cronjob_nexttime', 0);
if (0 !== $nexttime && time() >= $nexttime) {
    $env = rex_cronjob_manager::getCurrentEnvironment();
    $EP = 'backend' === $env ? 'PAGE_CHECKED' : 'PACKAGES_INCLUDED';
    rex_extension::register($EP, static function () use ($env) {
        if ('backend' !== $env || !in_array(rex_be_controller::getCurrentPagePart(1), ['setup', 'login', 'cronjob'], true)) {
            rex_cronjob_manager_sql::factory()->check();
        }
    });
}

if (isset($REX['LOAD_PAGE']) && $REX['LOAD_PAGE']) {
    unset($REX);
    require rex_path::core(rex::isBackend() ? 'backend.php' : 'frontend.php');
}

if (rex::isSetup()) {
    return;
}

rex_user::setRoleClass(rex_user_role::class);

rex_perm::register('users[]');

rex_extension::register('COMPLEX_PERM_REMOVE_ITEM', [rex_user_role::class, 'removeOrReplaceItem']);
rex_extension::register('COMPLEX_PERM_REPLACE_ITEM', [rex_user_role::class, 'removeOrReplaceItem']);

if (!rex::isBackend() && 0 != rex::getConfig('phpmailer_errormail')) {
    rex_extension::register('RESPONSE_SHUTDOWN', static function () {
        rex_mailer::errorMail();
    });
}

if ('system' == rex_be_controller::getCurrentPagePart(1)) {
    rex_system_setting::register(new rex_system_setting_phpmailer_errormail());
}

// make the phpmailer addon icon orange if detour_mode is active
if (true == rex::getConfig('phpmailer_detour_mode')) {
    $page = rex_be_controller::getPageObject('phpmailer');
    $page->setIcon($page->getIcon().' text-danger');
}

// ---------------------------------- Codemirror ----------------------------------

/**
 * REDAXO customizer.
 *
 * Codemirror by : http://codemirror.net/
 * Marijn Haverbeke <marijnh@gmail.com>
 */

/* Output CodeMirror-CSS */
if (rex::isBackend() && 'css' == rex_request('codemirror_output', 'string', '')) {
    rex_response::cleanOutputBuffers();
    header('Content-type: text/css');

    $filenames = [];
    $filenames[] = rex_url::coreAssets('vendor/codemirror/codemirror.min.css');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/display/fullscreen.css');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/theme/' . rex::getConfig('be_style_codemirror_theme') . '.css');
    if ('' != rex_request('themes', 'string', '')) {
        $themes = explode(',', rex_request('themes', 'string', ''));
        foreach ($themes as $theme) {
            if (preg_match('/[a-z0-9\._-]+/i', $theme)) {
                $filenames[] = rex_url::coreAssets('vendor/codemirror/theme/' . $theme . '.css');
            }
        }
    }
    if (rex::getConfig('be_style_codemirror_tools')) {
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/fold/foldgutter.css');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/dialog/dialog.css');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/search/matchesonscrollbar.css');
    }
    $filenames[] = rex_url::coreAssets('vendor/codemirror/codemirror-additional.css');
    if (rex::getConfig('be_style_codemirror_autoresize')) {
        $filenames[] = rex_url::coreAssets('vendor/codemirror/codemirror-autoresize.css');
    }

    $content = '';
    foreach ($filenames as $filename) {
        $content .= '/* ' . $filename . ' */' . "\n" . rex_file::get($filename) . "\n";
    }

    header('Pragma: cache');
    header('Cache-Control: public');
    header('Expires: ' . date('D, j M Y', strtotime('+1 week')) . ' 00:00:00 GMT');
    echo $content;

    exit;
}

/* Output CodeMirror-JavaScript */
if (rex::isBackend() && 'javascript' == rex_request('codemirror_output', 'string', '')) {
    rex_response::cleanOutputBuffers();
    header('Content-Type: application/javascript');

    $filenames = [];
    $filenames[] = rex_url::coreAssets('vendor/codemirror/codemirror.min.js');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/display/autorefresh.js');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/display/fullscreen.js');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/selection/active-line.js');

    if (rex::getConfig('be_style_codemirror_tools')) {
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/fold/foldcode.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/fold/foldgutter.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/fold/brace-fold.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/fold/xml-fold.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/fold/indent-fold.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/fold/markdown-fold.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/fold/comment-fold.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/edit/closebrackets.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/edit/matchtags.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/edit/matchbrackets.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/mode/overlay.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/dialog/dialog.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/search/searchcursor.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/search/search.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/scroll/annotatescrollbar.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/search/matchesonscrollbar.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/addon/search/jump-to-line.js');
    }

    $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/xml/xml.js');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/htmlmixed/htmlmixed.js');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/htmlembedded/htmlembedded.js');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/javascript/javascript.js');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/css/css.js');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/clike/clike.js');
    $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/php/php.js');

    if (rex::getConfig('be_style_codemirror_langs')) {
        $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/markdown/markdown.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/textile/textile.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/gfm/gfm.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/yaml/yaml.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/yaml-frontmatter/yaml-frontmatter.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/meta.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/properties/properties.js');
        $filenames[] = rex_url::coreAssets('vendor/codemirror/mode/sql/sql.js');
    }

    $content = '';
    foreach ($filenames as $filename) {
        $content .= '/* ' . $filename . ' */' . "\n" . rex_file::get($filename) . "\n";
    }

    header('Pragma: cache');
    header('Cache-Control: public');
    header('Expires: ' . date('D, j M Y', strtotime('+1 week')) . ' 00:00:00 GMT');
    echo $content;

    exit;
}

if (rex::isBackend() && rex::getUser()) {
    /* Codemirror */
    if (rex::getConfig('be_style_codemirror')) {
        // JsProperty CodeMirror-Theme
        rex_view::setJsProperty('customizer_codemirror_defaulttheme', rex::getConfig('be_style_codemirror_theme'));
        rex_view::setJsProperty('customizer_codemirror_defaultdarktheme', rex::getConfig('be_style_codemirror_darktheme', 'dracula'));
        // JsProperty CodeMirror-Selectors
        $selectors = 'textarea.rex-code, textarea.rex-js-code, textarea.codemirror';
        if ('' != rex::getConfig('be_style_codemirror_selectors')) {
            $selectors = $selectors . ', ' . rex::getConfig('be_style_codemirror_selectors');
        }
        rex_view::setJsProperty('customizer_codemirror_selectors', $selectors);
        // JsProperty CodeMirror-Autoresize
        if (rex::getConfig('be_style_codemirror_autoresize')) {
            rex_view::setJsProperty('customizer_codemirror_autoresize', rex::getConfig('be_style_codemirror_autoresize'));
        }
        // JsProperty Codemirror-Options
        rex_view::setJsProperty('customizer_codemirror_options', str_replace(["\n", "\r"], '', trim(rex::getConfig('be_style_codemirror_options', ''))));
        // JsProperty JS/CSS-Buster
        $mtimejs = filemtime(rex_url::coreAssets('vendor/codemirror/codemirror.min.js'));
        $mtimecss = filemtime(rex_url::coreAssets('vendor/codemirror/codemirror.min.css'));
        if (isset($_SESSION['codemirror_reload'])) {
            $mtimejs .= $_SESSION['codemirror_reload'];
            $mtimecss .= $_SESSION['codemirror_reload'];
        }
        rex_view::setJsProperty('customizer_codemirror_jsbuster', $mtimejs);
        rex_view::setJsProperty('customizer_codemirror_cssbuster', $mtimecss);
    }

    /* Customizer Ergänzungen */
    rex_view::addCssFile(rex_url::coreAssets('css/customizer.css'));
    rex_view::addJsFile(rex_url::coreAssets('js/customizer.js'), [rex_view::JS_IMMUTABLE => true]);

    if ('' != rex::getConfig('be_style_labelcolor')) {
        rex_view::setJsProperty('customizer_labelcolor', rex::getConfig('be_style_labelcolor'));
    }
    if (rex::getConfig('be_style_showlink')) {
        rex_view::setJsProperty(
            'customizer_showlink',
            '<h1 class="be-style-customizer-title"><a href="' . rex_url::frontend() . '" target="_blank" rel="noreferrer noopener"><span class="be-style-customizer-title-name">' . rex_escape(rex::getServerName()) . '</span><i class="fa fa-external-link"></i></a></h1>',
        );
    }
}
