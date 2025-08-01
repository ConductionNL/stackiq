<?php
namespace OCA\SoftwareCatalog\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

class SoftwareCatalogAdmin implements ISettings {
    private IL10N $l;
    private IAppConfig $config;

    public function __construct(IAppConfig $config, IL10N $l) {
        $this->config = $config;
        $this->l = $l;
    }

    /**
     * @return TemplateResponse
     */
    public function getForm() {
        $parameters = [
            'mySetting' => $this->config->getValueString('softwarecatalog', 'software_catalog_setting', 'true') === 'true',
        ];

        return new TemplateResponse('softwarecatalog', 'settings/admin', $parameters, 'admin');
    }

    public function getSection() {
        // Name of the previously created section.
        $sectionName = 'softwarecatalog';
        return $sectionName;
    }

    /**
     * @return int whether the form should be rather on the top or bottom of
     * the admin section. The forms are arranged in ascending order of the
     * priority values. It is required to return a value between 0 and 100.
     *
     * E.g.: 70
     */
    public function getPriority() {
        return 10;
    }
}