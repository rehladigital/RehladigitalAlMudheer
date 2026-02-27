<?php

namespace Leantime\Domain\Setting\Controllers;

use Leantime\Core\Configuration\Environment;
use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Core\UI\Theme;
use Leantime\Domain\Api\Services\Api as ApiService;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\Notifications\Models\Notification;
use Leantime\Domain\Projects\Repositories\Projects as ProjectRepository;
use Leantime\Domain\Reports\Services\Reports as ReportService;
use Leantime\Domain\Setting\Repositories\Setting as SettingRepository;
use Leantime\Domain\Setting\Services\RepoUpdater;
use Leantime\Domain\Setting\Services\Setting as SettingService;

class EditCompanySettings extends Controller
{
    private SettingRepository $settingsRepo;

    private ApiService $APIService;

    private SettingService $settingsSvc;

    private Theme $theme;

    private Environment $config;

    private ProjectRepository $projectRepo;

    private RepoUpdater $repoUpdater;

    /**
     * constructor - initialize private variables
     */
    public function init(
        SettingRepository $settingsRepo,
        ApiService $APIService,
        SettingService $settingsSvc,
        RepoUpdater $repoUpdater,
        Theme $theme,
        Environment $config,
        ProjectRepository $projectRepo,

    ) {
        Auth::authOrRedirect([Roles::$owner, Roles::$admin], true);

        $this->settingsRepo = $settingsRepo;
        $this->APIService = $APIService;
        $this->settingsSvc = $settingsSvc;
        $this->repoUpdater = $repoUpdater;
        $this->theme = $theme;
        $this->config = $config;
        $this->projectRepo = $projectRepo;
    }

    /**
     * get - handle get requests
     */
    public function get($params)
    {
        if (! Auth::userIsAtLeast(Roles::$owner)) {
            return $this->tpl->display('errors.error403', responseCode: 403);
        }

        if (isset($_GET['resetLogo'])) {
            $this->settingsSvc->resetLogo();

            return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings#look');
        }

        $companySettings = [
            'logo' => $this->theme->getLogoUrl(),
            'primarycolor' => session('companysettings.primarycolor') ?? '',
            'secondarycolor' => session('companysettings.secondarycolor') ?? '',
            'name' => session('companysettings.sitename'),
            'language' => session('companysettings.language'),
            'telemetryActive' => true,
            'messageFrequency' => '',
            'microsoftAuth' => [
                'enabled' => $this->config->oidcEnable,
                'issuer' => $this->config->oidcProviderUrl,
                'clientId' => $this->config->oidcClientId,
                'allowPublicRegistration' => $this->config->oidcCreateUser,
                'defaultRole' => $this->config->oidcDefaultRole,
                'hasClientSecret' => ! empty($this->config->oidcClientSecret),
            ],
        ];

        $msEnabled = $this->settingsRepo->getSetting('companysettings.microsoftAuth.enabled');
        if ($msEnabled !== false) {
            $companySettings['microsoftAuth']['enabled'] = $this->toBool($msEnabled);
        }

        $msIssuer = $this->settingsRepo->getDecryptedSetting('companysettings.microsoftAuth.issuer');
        if ($msIssuer !== false) {
            $companySettings['microsoftAuth']['issuer'] = $msIssuer;
        }

        $msClientId = $this->settingsRepo->getDecryptedSetting('companysettings.microsoftAuth.clientId');
        if ($msClientId !== false) {
            $companySettings['microsoftAuth']['clientId'] = $msClientId;
        }

        $msAllowRegistration = $this->settingsRepo->getSetting('companysettings.microsoftAuth.allowPublicRegistration');
        if ($msAllowRegistration !== false) {
            $companySettings['microsoftAuth']['allowPublicRegistration'] = $this->toBool($msAllowRegistration);
        }

        $msDefaultRole = $this->settingsRepo->getSetting('companysettings.microsoftAuth.defaultRole');
        if ($msDefaultRole !== false) {
            $companySettings['microsoftAuth']['defaultRole'] = (int) $msDefaultRole;
        }

        $msClientSecret = $this->settingsRepo->getDecryptedSetting('companysettings.microsoftAuth.clientSecret');
        if ($msClientSecret !== false) {
            $companySettings['microsoftAuth']['hasClientSecret'] = ! empty($msClientSecret);
        }

        $mainColor = $this->settingsRepo->getSetting('companysettings.mainColor');
        if ($mainColor !== false) {
            $companySettings['primarycolor'] = '#'.$mainColor;
            $companySettings['secondarycolor'] = '#'.$mainColor;
        }

        $primaryColor = $this->settingsRepo->getSetting('companysettings.primarycolor');
        if ($primaryColor !== false) {
            $companySettings['primarycolor'] = $primaryColor;
        }

        $secondaryColor = $this->settingsRepo->getSetting('companysettings.secondarycolor');
        if ($secondaryColor !== false) {
            $companySettings['secondarycolor'] = $secondaryColor;
        }

        $sitename = $this->settingsRepo->getSetting('companysettings.sitename');
        if ($sitename !== false) {
            $companySettings['name'] = $sitename;
        }

        $language = $this->settingsRepo->getSetting('companysettings.language');
        if ($language !== false) {
            $companySettings['language'] = $language;
        }

        $messageFrequency = $this->settingsRepo->getSetting('companysettings.messageFrequency');
        if ($messageFrequency !== false) {
            $companySettings['messageFrequency'] = $messageFrequency;
        }

        // Load default notification event types
        $defaultNotificationTypes = $this->settingsRepo->getSetting('companysettings.defaultNotificationEventTypes');
        $allCategories = array_keys(Notification::NOTIFICATION_CATEGORIES);
        if ($defaultNotificationTypes) {
            $defaultNotificationTypes = json_decode($defaultNotificationTypes, true);
        }
        if (! is_array($defaultNotificationTypes)) {
            $defaultNotificationTypes = $allCategories;
        }

        // Load default notification relevance level
        $defaultRelevance = $this->settingsRepo->getSetting('companysettings.defaultNotificationRelevance');
        if (! $defaultRelevance || ! Notification::isValidRelevanceLevel($defaultRelevance)) {
            $defaultRelevance = Notification::RELEVANCE_ALL;
        }

        $apiKeys = $this->APIService->getAPIKeys();
        $currentProjectId = (int) (session('currentProject') ?? 0);
        $currentProject = $currentProjectId > 0 ? $this->projectRepo->getProject($currentProjectId) : false;
        $availableVersions = [];
        $currentVersionRef = '';
        $versionStatus = [];
        if (Auth::userIsAtLeast(Roles::$owner)) {
            $availableVersions = $this->repoUpdater->listVersions();
            $currentVersionRef = $this->repoUpdater->getCurrentRef();
            $versionStatus = $this->repoUpdater->getVersionStatus();
        }

        $this->tpl->assign('apiKeys', $apiKeys);
        $this->tpl->assign('languageList', $this->language->getLanguageList());
        $this->tpl->assign('companySettings', $companySettings);
        $this->tpl->assign('isOwner', Auth::userIsAtLeast(Roles::$owner));
        $this->tpl->assign('currentProjectId', $currentProjectId);
        $this->tpl->assign('currentProject', $currentProject);
        $this->tpl->assign('availableVersions', $availableVersions);
        $this->tpl->assign('currentVersionRef', $currentVersionRef);
        $this->tpl->assign('versionStatus', $versionStatus);
        $this->tpl->assign('notificationCategories', Notification::NOTIFICATION_CATEGORIES);
        $this->tpl->assign('defaultNotificationTypes', $defaultNotificationTypes);
        $this->tpl->assign('defaultRelevance', $defaultRelevance);
        $this->tpl->assign('relevanceLevels', [
            Notification::RELEVANCE_ALL => 'label.notifications_all_activity',
            Notification::RELEVANCE_MY_WORK => 'label.notifications_my_work',
        ]);

        return $this->tpl->display('setting.editCompanySettings');
    }

    /**
     * post - handle post requests
     */
    public function post($params)
    {
        if (isset($params['clearProjectTasks'])) {
            if (! Auth::userIsAtLeast(Roles::$owner)) {
                return $this->tpl->display('errors.error403', responseCode: 403);
            }

            $projectId = (int) ($params['projectId'] ?? session('currentProject') ?? 0);
            if ($projectId <= 0) {
                $this->tpl->setNotification('No active project selected to clear.', 'error');

                return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings#details');
            }

            $project = $this->projectRepo->getProject($projectId);
            if ($project === false) {
                $this->tpl->setNotification('Project was not found.', 'error');

                return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings#details');
            }

            $deletedCount = $this->projectRepo->clearProjectTasks($projectId);
            $projectName = (string) ($project['name'] ?? ('#'.$projectId));
            $this->tpl->setNotification(
                'Cleared '.$deletedCount.' tasks from project "'.$projectName.'".',
                'success'
            );

            return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings#details');
        }

        if (isset($params['runRepoUpdate'])) {
            if (! Auth::userIsAtLeast(Roles::$owner)) {
                return $this->tpl->display('errors.error403', responseCode: 403);
            }

            if (! $this->repoUpdater->canUseGit()) {
                $this->tpl->setNotification('Git is not available on this server. Use ZIP package update instead.', 'error');

                return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings#details');
            }

            $targetVersion = trim((string) ($params['targetVersion'] ?? ''));
            if ($targetVersion === '') {
                $this->tpl->setNotification('Please select a version before updating.', 'error');

                return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings#details');
            }

            try {
                $result = $this->repoUpdater->updateToVersion($targetVersion);
                $notificationType = ! empty($result['ok']) ? 'success' : 'error';
                $this->tpl->setNotification(
                    $result['message'] ?? 'Update command completed with unknown status.',
                    $notificationType
                );
            } catch (\Throwable $e) {
                report($e);
                $this->tpl->setNotification('Update failed unexpectedly. Please check server logs.', 'error');
            }

            return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings#details');
        }

        if (isset($params['runPackageUpdate'])) {
            if (! Auth::userIsAtLeast(Roles::$owner)) {
                return $this->tpl->display('errors.error403', responseCode: 403);
            }

            $upload = $_FILES['updatePackage'] ?? null;
            if (! is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $this->tpl->setNotification('Please choose a valid ZIP file before running package update.', 'error');

                return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings#details');
            }

            try {
                $result = $this->repoUpdater->updateFromUploadedArchive(
                    (string) ($upload['tmp_name'] ?? ''),
                    (string) ($upload['name'] ?? '')
                );
                $notificationType = ! empty($result['ok']) ? 'success' : 'error';
                $this->tpl->setNotification($result['message'] ?? 'Package update finished with unknown status.', $notificationType);
            } catch (\Throwable $e) {
                report($e);
                $this->tpl->setNotification('Package update failed unexpectedly. Please check server logs.', 'error');
            }

            return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings#details');
        }

        // Look & feel updates
        if (isset($params['primarycolor']) && $params['primarycolor'] != '') {
            $this->settingsRepo->saveSetting('companysettings.primarycolor', htmlentities(addslashes($params['primarycolor'])));
            $this->settingsRepo->saveSetting('companysettings.secondarycolor', htmlentities(addslashes($params['secondarycolor'])));

            // Check if main color is still in the system
            // if so remove. This call should be removed in a few versions.
            $mainColor = $this->settingsRepo->getSetting('companysettings.mainColor');
            if ($mainColor !== false) {
                $this->settingsRepo->deleteSetting('companysettings.mainColor');
            }

            session(['companysettings.primarycolor' => htmlentities(addslashes($params['primarycolor']))]);
            session(['companysettings.secondarycolor' => htmlentities(addslashes($params['secondarycolor']))]);

            $this->tpl->setNotification($this->language->__('notifications.company_settings_edited_successfully'), 'success');
        }

        // Main Details
        if (isset($params['name']) && $params['name'] != '' && isset($params['language']) && $params['language'] != '') {
            $this->settingsRepo->saveSetting('companysettings.sitename', htmlspecialchars(addslashes($params['name'])));
            $this->settingsRepo->saveSetting('companysettings.language', htmlentities(addslashes($params['language'])));
            $this->settingsRepo->saveSetting('companysettings.messageFrequency', (int) $params['messageFrequency']);

            // Clear the localization cache so middleware re-fetches on next request
            session()->forget('localization.cached');

            // Save default notification event types
            $defaultEventTypes = $params['defaultNotificationEventTypes'] ?? [];
            if (! is_array($defaultEventTypes)) {
                $defaultEventTypes = [];
            }
            $validCategories = array_keys(Notification::NOTIFICATION_CATEGORIES);
            $defaultEventTypes = array_values(array_intersect($defaultEventTypes, $validCategories));
            $this->settingsRepo->saveSetting(
                'companysettings.defaultNotificationEventTypes',
                json_encode($defaultEventTypes)
            );

            // Save default notification relevance level
            $defaultRelevance = $params['defaultNotificationRelevance'] ?? Notification::RELEVANCE_ALL;
            if (! Notification::isValidRelevanceLevel($defaultRelevance)) {
                $defaultRelevance = Notification::RELEVANCE_ALL;
            }
            $this->settingsRepo->saveSetting('companysettings.defaultNotificationRelevance', $defaultRelevance);

            session(['companysettings.sitename' => htmlspecialchars(addslashes($params['name']))]);
            session(['companysettings.language' => htmlentities(addslashes($params['language']))]);

            if (isset($_POST['telemetryActive'])) {
                $this->settingsRepo->saveSetting('companysettings.telemetry.active', 'true');
            } else {
                // Set remote telemetry to false:
                app()->make(ReportService::class)->optOutTelemetry();
            }

            $microsoftAuthEnabled = isset($params['microsoftAuthEnabled']) ? 'true' : 'false';
            $this->settingsRepo->saveSetting('companysettings.microsoftAuth.enabled', $microsoftAuthEnabled);

            $microsoftIssuer = trim((string) ($params['microsoftAuthIssuer'] ?? ''));
            $this->settingsRepo->saveEncryptedSetting('companysettings.microsoftAuth.issuer', $microsoftIssuer);

            $microsoftClientId = trim((string) ($params['microsoftAuthClientId'] ?? ''));
            $this->settingsRepo->saveEncryptedSetting('companysettings.microsoftAuth.clientId', $microsoftClientId);

            $microsoftAllowRegistration = isset($params['microsoftAuthAllowPublicRegistration']) ? 'true' : 'false';
            $this->settingsRepo->saveSetting(
                'companysettings.microsoftAuth.allowPublicRegistration',
                $microsoftAllowRegistration
            );

            $microsoftDefaultRole = (int) ($params['microsoftAuthDefaultRole'] ?? 20);
            $validRoles = [5, 10, 20, 30, 40, 50];
            if (! in_array($microsoftDefaultRole, $validRoles, true)) {
                $microsoftDefaultRole = 20;
            }
            $this->settingsRepo->saveSetting('companysettings.microsoftAuth.defaultRole', $microsoftDefaultRole);

            $microsoftClientSecret = trim((string) ($params['microsoftAuthClientSecret'] ?? ''));
            if ($microsoftClientSecret !== '') {
                $this->settingsRepo->saveEncryptedSetting(
                    'companysettings.microsoftAuth.clientSecret',
                    $microsoftClientSecret
                );
            }

            $this->tpl->setNotification($this->language->__('notifications.company_settings_edited_successfully'), 'success');
        }

        return Frontcontroller::redirect(BASE_URL.'/setting/editCompanySettings');
    }

    /**
     * put - handle put requests
     */
    public function put($params) {}

    /**
     * delete - handle delete requests
     */
    public function delete($params) {}

    private function toBool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
