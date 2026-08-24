<?php

use OCP\Util;

$appId = OCA\Stackiq\AppInfo\Application::APP_ID;
// The webpack build emits a separate runtime chunk (runtimeChunk: { name: 'runtime' })
// plus shared vendor/nc-vue chunks. All must be loaded in dependency order BEFORE
// the entry chunk, otherwise __webpack_require__ is never bootstrapped and Vue never
// mounts. See GH issue #322 for the full diagnosis.
Util::addScript($appId, $appId . '-runtime');
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-main');
Util::addStyle($appId, 'main');
?>
<div id="stackiq"></div>


