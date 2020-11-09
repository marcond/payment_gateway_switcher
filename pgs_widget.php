<?php

// Informações úteis:
//
// [1]
// Necessario habilitar em wp-content/themes/martfury/functions.php usando:
// require ABSPATH . 'wp-content/payment_gateway_switcher/pgs_widget.php';
//

// Evita chamada direta
if (!defined ('ABSPATH')) 
{
	die;
}

if (!defined ('PGS_WIDGET_PATH')) 
{
	define( 'PGS_WIDGET_PATH', trailingslashit (plugin_dir_path (__FILE__)));
}

define ('PGS_WIDGET_VERSION', '0.9.0');
define ('PGS_WIDGET_TEXT_DOMAIN', 'pgs-widget');

if (!class_exists ('PGS_Widget'))
{
    class PGS_Widget extends WP_Widget
    {
        public static $instance;

        public function __construct ()
        {
            load_plugin_textdomain (PGS_WIDGET_TEXT_DOMAIN, false, dirname (plugin_basename (__FILE__)));

            parent::__construct
            (
                'pgswidget', // Base ID.
                esc_html__('PGS Widget', PGS_WIDGET_TEXT_DOMAIN), // Nome
                array ('description' => esc_html__('Adiciona Códigos do PGS .', PGS_WIDGET_TEXT_DOMAIN))
            );
        }

        public function widget ($args, $instance)
        {
            $pgs_wid_type    = $instance ['pgs_wid_type'];
            $pgs_wid_content = apply_filters ('pgs_wid_content', $instance ['pgs_wid_content'], $this);

            if ( 'php_code' == $pgs_wid_type ) 
            {
                $pgs_wid_final_content = $this->php_exe( $pgs_wid_content );
            }

            if ( 'short_code' == $pgs_wid_type ) 
            {
                $pgs_wid_final_content = do_shortcode( $pgs_wid_content );
            }

            if ( 'html_code' == $pgs_wid_type ) 
            {
                $pgs_wid_final_content = convert_smilies( balanceTags( $pgs_wid_content ) );
            }

            if ( 'text_code' == $pgs_wid_type ) 
            {
                $pgs_wid_final_content = wptexturize( esc_html( $pgs_wid_content ) );
            }

            $pgs_wid_final_content = apply_filters( 'pgs_wid_final_content', $pgs_wid_final_content );

            // Gera o html do widget
            echo $args['before_widget'];
            echo '<div class="pgs-widget">' . $pgs_wid_final_content . '</div>';
            echo $args['after_widget'];
        }

        public function form( $instance ) 
        {
            $title = ! empty( $instance['title'] ) ? $instance['title'] : esc_html__( 'New title', PGS_WIDGET_TEXT_DOMAIN );
            if ( 0 == count( $instance ) ) 
            {
                $instance['pgs_wid_type']    = ! empty( $instance['pgs_wid_type'] ) ? $instance['pgs_wid_type'] : 'short_code';
                $instance['pgs_wid_content'] = ! empty( $instance['pgs_wid_content'] ) ? $instance['pgs_wid_content'] : esc_html__( 'your code ....', PGS_WIDGET_TEXT_DOMAIN );
            } 
            else 
            {
                $instance['pgs_wid_type']    = $instance['pgs_wid_type'];
                $instance['pgs_wid_content'] = $instance['pgs_wid_content'];
            }
            ?>
                <p>
                <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_attr_e( 'Título:', PGS_WIDGET_TEXT_DOMAIN ); ?></label>
                <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
                value="<?php echo esc_attr( $title ); ?>">
                </p>
                <p>
                <label for="<?php echo esc_attr( $this->get_field_id( 'pgs_wid_type' ) ); ?>">
                    <?php esc_attr_e( 'Tipo:', PGS_WIDGET_TEXT_DOMAIN ); ?>
                </label>
                </p>
                <select  name="<?php echo esc_html( $this->get_field_name( 'pgs_wid_type' ) ); ?>" class="widefat" id="<?php esc_html_e( $this->get_field_id( 'pgs_wid_type' ) ); ?>">
                <option value="short_code" <?php selected( $instance['pgs_wid_type'], 'short_code' ); ?>>
                    <?php esc_attr_e( 'Short Code', PGS_WIDGET_TEXT_DOMAIN ); ?>
                </option>
                <option value="php_code"   <?php selected( $instance['pgs_wid_type'], 'php_code' ); ?>> 
                    <?php esc_attr_e( 'PHP Code', PGS_WIDGET_TEXT_DOMAIN ); ?>
                </option>
                <option value="html_code"  <?php selected( $instance['pgs_wid_type'], 'html_code' ); ?>>
                    <?php esc_attr_e( 'HTML', PGS_WIDGET_TEXT_DOMAIN ); ?>
                </option>
                <option value="text_code"  <?php selected( $instance['pgs_wid_type'], 'text_code' ); ?>>
                    <?php esc_attr_e( 'Text', PGS_WIDGET_TEXT_DOMAIN ); ?>
                </option>
                </select>
                <p>
                <textarea class="widefat" rows="12" cols="20" id="<?php echo esc_attr( $this->get_field_id( 'pgs_wid_content' ) ); ?>"
                name="<?php echo esc_attr( $this->get_field_name( 'pgs_wid_content' ) ); ?>"><?php echo $instance['pgs_wid_content']; ?></textarea>
                </p>
            <?php
        }

        public function update( $new_instance, $old_instance ) 
        {
            $instance = array();
            $instance['title'] = (!empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
            $instance['pgs_wid_type'] = (!empty( $new_instance['pgs_wid_type'] ) ) ? strip_tags( $new_instance['pgs_wid_type'] ) : '';
            $instance['pgs_wid_content'] = (!empty( $new_instance['pgs_wid_content'] ) ) ? strip_tags( $new_instance['pgs_wid_content'] ) : '';

            if ( current_user_can( 'unfiltered_html' ) ) 
            {
                $instance['pgs_wid_content'] = $new_instance['pgs_wid_content'];
            } 
            else 
            {
                $instance['pgs_wid_content'] = stripslashes( wp_filter_post_kses( $new_instance['pgs_wid_content'] ) );
            }
            return $instance;
        }

        private function php_exe( $content ) 
        {
            apply_filters ('before_pgs_wid_php_exe', $content);
            ob_start();
            eval( '?>' . $content );
            $text = ob_get_contents();
            ob_end_clean();
            return apply_filters ('after_pgs_wid_php_exe', $text);
        }

        public static function get_instance ()
        {
            // If the single instance hasn't been set, set it now.
            if (null == self::$instance)
            {
                self::$instance = new self ();
            }
            return self::$instance;
        }
    } // PGS_Widget

    function register_pgs_widget ()
    {
        register_widget ('PGS_Widget');
    }
    add_action ('widgets_init', 'register_pgs_widget');
}

// EOF
