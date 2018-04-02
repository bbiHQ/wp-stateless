<?php
/**
 * Plugin Name: Easy Digital Downloads
 * Plugin URI: https://wordpress.org/plugins/easy-digital-downloads/
 *
 * Compatibility Description: 
 *
 */

namespace wpCloud\StatelessMedia {

    if(!class_exists('wpCloud\StatelessMedia\Imagify')) {
        
        class Imagify extends ICompatibility {
            protected $id = 'imagify';
            protected $title = 'Imagify';
            protected $constant = 'WP_STATELESS_COMPATIBILITY_IMAGIFY';
            protected $description = 'Ensures compatibility with Imagify compression plugin.';
            protected $plugin_constant = 'IMAGIFY_VERSION';

            public function module_init($sm){
                // We need to remove the regular handler for sync 
                // unless in stateless mode we would remove the attachment before it's get optimized.
                remove_filter( 'wp_update_attachment_metadata', array( "wpCloud\StatelessMedia\Utility", 'add_media' ), 999 );
                add_filter( 'wp_update_attachment_metadata', array( $this, 'add_media_wrapper' ), 999, 2 );
                add_action( 'after_imagify_optimize_attachment', array($this, 'after_imagify_optimize_attachment'), 10 );
                add_action( 'after_imagify_restore_attachment', array($this, 'after_imagify_optimize_attachment'), 10 );
                // add_filter( 'get_attached_file', array($this, 'fix_missing_file'), 10, 2 );
                add_filter( 'before_imagify_optimize_attachment', array($this, 'fix_missing_file'), 10);
                
            }

            public function add_media_wrapper($metadata, $attachment_id){
                $imagify = new \Imagify_Attachment($attachment_id);
                if ( ! $imagify->is_extension_supported() ) {
                    return ud_get_stateless_media()->add_media( $metadata, $id );
                }
                return $metadata;
            }

            /**
             * Try to restore images before compression
             *
             * @param $file
             * @param $attachment_id
             * @return mixed
             */
            public function fix_missing_file( $attachment_id ) {
                /**
                 * If hook is triggered by ShortPixel
                 */
                if ( !$this->hook_from_imagify() ) return;

                /**
                 * If mode is stateless then we change it to cdn in order images not being deleted before optimization
                 * Remember that we changed mode via global var
                 */
                if ( ud_get_stateless_media()->get( 'sm.mode' ) == 'stateless' ) {
                    ud_get_stateless_media()->set( 'sm.mode', 'cdn' );
                    global $wp_stateless_imagify_mode;
                    $wp_stateless_imagify_mode = 'stateless';
                }

                $upload_dir = wp_upload_dir();
                $meta_data = wp_get_attachment_metadata( $attachment_id );
                $file = $upload_dir[ 'basedir' ] . "/" . $meta_data['file'];

                /**
                 * Try to get all missing files from GCS
                 */
                if ( !file_exists( $file ) ) {
                    ud_get_stateless_media()->get_client()->get_media( apply_filters( 'wp_stateless_file_name', str_replace( trailingslashit( $upload_dir[ 'basedir' ] ), '', $file )), true, $file );
                }

                if ( !empty( $meta_data['sizes'] ) && is_array( $meta_data['sizes'] ) ) {
                    foreach( $meta_data['sizes'] as $image ) {
                        if ( !empty( $image['gs_name'] ) && !file_exists( $file = trailingslashit( $upload_dir[ 'basedir' ] ).$image['gs_name'] ) ) {
                            ud_get_stateless_media()->get_client()->get_media( apply_filters( 'wp_stateless_file_name', $image['gs_name']), true, $file );
                        }
                    }
                }

            }

            /**
             * Determine where we hook from
             * We need to do this only for something specific in shortpixel plugin
             *
             * @return bool
             */
            private function hook_from_imagify() {
                $call_stack = debug_backtrace();

                if ( !empty( $call_stack ) && is_array( $call_stack ) ) {
                    foreach( $call_stack as $step ) {
                        if ( $step['function'] == 'optimize' && $step['class'] == 'Imagify_Attachment' ) {
                            return true;
                        }
                    }
                }

                return false;
            }


            /**
             * If image size not exist then upload it to GS.
             * 
             * $args = array(
             *      'thumbnail' => $thumbnail,
             *      'p_img_large' => $p_img_large,
             *   )
             */
            public function after_imagify_optimize_attachment($id){
                /**
                 * Restore stateless mode if needed
                 */
                global $wp_stateless_imagify_mode;
                if ( $wp_stateless_imagify_mode == 'stateless' ) {
                    ud_get_stateless_media()->set( 'sm.mode', 'stateless' );
                }

                $metadata = wp_get_attachment_metadata( $id );
                ud_get_stateless_media()->add_media( $metadata, $id, true );
            }
            
            
        }

    }

}