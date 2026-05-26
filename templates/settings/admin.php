<?php
use OCP\Util;

$appId = OCA\SoftwareCatalog\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
Util::addStyle($appId, 'main');

?>

<div id="settings"></div>