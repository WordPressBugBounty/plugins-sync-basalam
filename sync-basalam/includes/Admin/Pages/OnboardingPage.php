<?php

namespace SyncBasalam\Admin\Pages;

use SyncBasalam\Admin\Onboarding\OnboardingManager;

defined('ABSPATH') || exit;
class OnboardingPage extends AdminPageAbstract
{
    public $checkToken = false;

    protected function renderContent()
    {
        $class = new OnboardingManager();
        $current_step = $class->getCurrentStep();
        $steps = $class->onboardingSteps();
        $total_steps = count($steps);

        if ($current_step === $total_steps) update_option('sync_basalam_onboarding_completed', true);

        require_once syncBasalamPlugin()->templatePath('/admin/onboarding/template-onboarding-page.php');
    }
}
