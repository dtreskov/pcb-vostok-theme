<?php
/** Скрипты вкладки SEO: общие формы из crm.js + превью и загрузка картинок из seo.js. */
defined( 'ABSPATH' ) || exit;
?>
<p class="crm-msg" role="status" hidden></p>
<script>window.PCBCrm = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( 'pcb-panel/v1/requests/' ) ), 'api' => esc_url_raw( rest_url( 'pcb-panel/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/crm.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
<script src="<?php echo esc_url( PCB_PANEL_URL . 'assets/seo.js?ver=' . PCB_PANEL_VERSION ); ?>"></script>
