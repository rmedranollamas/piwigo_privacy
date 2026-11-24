<?php
declare(strict_types=1);

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

/**
 * Lifecycle manager for piwigo_privacy.
 * Works with all derivative_url_style settings (0=Auto, 1=Static, 2=i.php)
 * and non-standard plugin folder names via dynamic class_alias().
 */
class piwigo_privacy_maintain extends PluginMaintain
{
    /**
     * @param string $plugin_version
     * @param array<int, string> $errors
     * @return void
     */
    public function install($plugin_version, &$errors = array())
    {
    }

    /**
     * @param string $plugin_version
     * @param array<int, string> $errors
     * @return void
     */
    public function activate($plugin_version, &$errors = array())
    {
    }

    /**
     * @return void
     */
    public function deactivate()
    {
    }

    /**
     * @return void
     */
    public function uninstall()
    {
    }

    /**
     * @param string $old_version
     * @param string $new_version
     * @param array<int, string> $errors
     * @return void
     */
    public function update($old_version, $new_version, &$errors = array())
    {
    }
}

$pwgPrivacyMaintainClass = str_replace('-', '_', basename(__DIR__)) . '_maintain';
if ($pwgPrivacyMaintainClass !== 'piwigo_privacy_maintain' && !class_exists($pwgPrivacyMaintainClass, false)) {
    class_alias('piwigo_privacy_maintain', $pwgPrivacyMaintainClass);
}