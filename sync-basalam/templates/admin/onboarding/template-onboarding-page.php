<?php
if (! defined('ABSPATH')) exit;
?>
<div class="basalam-onboarding-wrapper">
    <div class="basalam-onboarding">
        <!-- Step Indicator Section -->
        <div class="step-indicator">
            <?php foreach ($steps as $step_number => $step) :
                $step_classes = ['step'];
                if ($step_number === $current_step) {
                    $step_classes[] = 'active';
                }
                if ($step_number < $current_step) {
                    $step_classes[] = 'completed';
                }
            ?>
                <div class="<?php echo esc_html(implode(' ', $step_classes)); ?>">
                    <span class="step-number"><?php echo esc_html($step_number); ?></span>
                    <span class="step-title"><?php echo esc_html($step['title']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Content Section -->
        <div class="step-content-wrapper">
            <h1><?php echo esc_html($steps[$current_step]['title']); ?></h1>

            <?php
            if (is_callable($steps[$current_step]['content'])) {
                $output = call_user_func($steps[$current_step]['content']);
                $allowed_tags = Sync_basalam_Admin_UI::allowed_html();

                echo wp_kses($output, $allowed_tags);
            } else {
                echo esc_html($steps[$current_step]['content']);
            }
            ?>
        </div>

        <!-- Navigation Section -->
        <div class="step-navigation">
            <?php if ($current_step == 1) : ?>
                <a href="<?php echo esc_url(admin_url() . "admin.php?page=sync_basalam"); ?>"
                    class="basalam-p basalam-a basalam-skip-onboarding" style="color: #0770d9 !important;">
                    گذر از مراحل
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=basalam-onboarding&step=' . ($current_step + 1))); ?>"
                    class="basalam-primary-button basalam-p basalam-a">
                    بعدی
                </a>
            <?php endif; ?>

            <?php if ($current_step == 2) : ?>
                <div class="nav-buttons">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=basalam-onboarding&step=' . ($current_step - 1))); ?>"
                        class="basalam-nav-link basalam-p basalam-p__small">
                        قبلی
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>