<?php
$uploadButton = $this->getVar('uploadButton');
$uploadButton['attributes'] = [
    'class' => [
        'btn',
        'btn-apply',
        'navbar-btn',
    ],
    'data-toggle' => 'modal',
    'data-target' => '#uploadModal',
];

$buttonGroupA = [$uploadButton];
$buttonGroupB = [
    ['attributes' => ['class' => ['btn', 'btn-primary', 'navbar-btn']], 'label' => '<i class="fa fa-picture-o"></i>'],
    ['attributes' => ['class' => ['btn', 'btn-primary', 'navbar-btn']], 'label' => '<i class="fa fa-film"></i>'],
    ['attributes' => ['class' => ['btn', 'btn-primary', 'navbar-btn']], 'label' => '<i class="fa fa-volume-up"></i>'],
    ['attributes' => ['class' => ['btn', 'btn-primary', 'navbar-btn']], 'label' => '<i class="fa fa-bath"></i>'],
    ['attributes' => ['class' => ['btn', 'btn-primary', 'navbar-btn']], 'label' => '<i class="fa fa-file-text"></i>'],
];
$buttonGroupC = [
    ['attributes' => ['class' => ['btn', 'btn-primary', 'navbar-btn']], 'label' => '<i class="fa fa-list"></i>'],
    ['attributes' => ['class' => ['btn', 'btn-primary', 'navbar-btn']], 'label' => '<i class="fa fa-th"></i>'],
    ['attributes' => ['class' => ['btn', 'btn-primary', 'navbar-btn']], 'label' => '<i class="fa fa-th-large"></i>'],
];
?>
<nav class="navbar navbar-inverse">
    <div class="container-fluid">
        <div class="navbar-left">
            <?= $this->subfragment('core/buttons/button_group.php', ['buttons' => $buttonGroupB]) ?>
        </div>
        <div class="navbar-right">
            <button class="btn btn-primary navbar-btn" data-toggle="button" data-target="#mediapool-wrapper">Filter</button>
        </div>
        <form class="navbar-right navbar-form">
            <div class="form-group">
                <input type="text" class="form-control" placeholder="Search">
            </div>
        </form>
    </div>
</nav>
<nav class="navbar navbar-inverse">
    <div class="container-fluid">
        <div class="navbar-left">
            <?= $this->subfragment('core/buttons/button_group.php', ['buttons' => $buttonGroupA]) ?>
        </div>
        <ul class="nav navbar-nav navbar-right">
            <li><p class="navbar-text">34 Elemente</p></li>
            <li>
                <div class="btn-toolbar">
                    <?= $this->subfragment('core/buttons/button_group.php', ['buttons' => $buttonGroupC]) ?>
                    <button class="btn btn-primary navbar-btn dropdown-toggle" data-toggle="dropdown" role="button">Sortierung <span class="caret"></span></button>
                    <ul class="dropdown-menu">
                        <li><a href="#">Nach Namen</a></li>
                        <li><a href="#">...</a></li>
                        <li><a href="#">...</a></li>
                        <li><a href="#">...</a></li>
                    </ul>
                </div>
            </li>
        </ul>
    </div>
</nav>

<div class="modal fade" id="uploadModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title"><?= rex_i18n::msg('pool_file_insert') ?></h4>
            </div>
            <div class="modal-body">
                Formular
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary"><?= rex_i18n::msg('pool_file_upload') ?></button>
            </div>
        </div>
    </div>
</div>
