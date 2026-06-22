<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HandLUTM_MergeTags extends NF_Abstracts_MergeTags
{
  /*
   * The $id property should match the array key where the class is registered.
   */
  protected $id = 'handl_utm_merge_tags';
  
  public function __construct()
  {
    parent::__construct();

    /* Plain strings until init; translations must not run before init (WP 6.7+). */
    $this->title = 'HandL UTM Grabber';

    $my_merge_tags = array();
    $fields = array('utm_source','utm_medium','utm_term', 'utm_content', 'utm_campaign', 'gclid', 'handl_original_ref', 'handl_landing_page', 'handl_ip', 'handl_ref', 'handl_url');
    foreach ($fields as $field){
    	$my_merge_tags[$field] = array(
          'id' => $field,
          'tag' => '{handl:'.$field.'}',
          'label' => $field,
          'callback' => function() use ($field) {
              return isset($_COOKIE[$field]) ? esc_html($_COOKIE[$field]) : '';
          }
        );
    }

    $this->merge_tags = $my_merge_tags;

    add_action( 'init', array( $this, 'init' ) );
    add_action( 'admin_init', array( $this, 'admin_init' ) );
  }

  public function init() {
    $this->title = __( 'HandL UTM Grabber', 'ninja-forms' );

    foreach ( array_keys( $this->merge_tags ) as $field ) {
      $this->merge_tags[ $field ]['label'] = __( $field, 'handl_utm_grabber' );
    }
  }

  public function admin_init(){ /* This section intentionally left blank. */ }
  
}
