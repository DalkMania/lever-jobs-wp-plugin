<?php
/*
Plugin Name: Lever Jobs Listings Plugin
Description: Lever is an online software that helps companies post jobs online, manage applicants and hire great employees.
Plugin URI: http://www.niklasdahlqvist.com
Author: Niklas Dahlqvist
Author URI: http://www.niklasdahlqvist.com
Version: 1.0.0
Requires at least: 4.8.3
License: GPL
*/

/*
   Copyright 2017  Niklas Dahlqvist  (email : dalkmania@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
* Ensure class doesn't already exist
*/
if(! class_exists ("Lever_Jobs_Plugin") ) {

  class Lever_Jobs_Plugin {
    private $options;
    private $apiBaseUrl;

    /**
     * Start up
     */
    public function __construct() {
      $this->options = get_option( 'lever_jobs_settings' );
      $this->site_url = $this->options['site_url'];
      $this->apiBaseUrl = 'https://api.lever.co/v0/postings/';

      add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
      add_action( 'admin_init', array( $this, 'page_init' ) );
      add_action('wp_enqueue_scripts', array($this,'plugin_admin_styles'));
      add_shortcode('lever_job_listings', array( $this,'JobsShortCode') );
    }

    public function plugin_admin_styles() {
      wp_enqueue_style('lever_jobs-admin-styles', $this->getBaseUrl() . '/assets/css/plugin-admin-styles.css');
    }

    /**
     * Add options page
     */
    public function add_plugin_page() {
        // This page will be under "Settings"
        add_management_page(
            'Lever Settings Admin',
            'Lever Settings',
            'manage_options',
            'lever_jobs-settings-admin',
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Options page callback
     */
    public function create_admin_page() {
        // Set class property
        $this->options = get_option( 'lever_jobs_settings' );
        ?>
        <div class="wrap lever_jobs-settings">
          <h2>Lever Settings</h2>
          <form method="post" action="options.php">
          <?php
              // This prints out all hidden setting fields
              settings_fields( 'lever_jobs_settings_group' );
              do_settings_sections( 'lever_jobs-settings-admin' );
              submit_button();
          ?>
          </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init() {

      register_setting(
          'lever_jobs_settings_group', // Option group
          'lever_jobs_settings', // Option name
          array( $this, 'sanitize' ) // Sanitize
      );

      add_settings_section(
          'lever_jobs_section', // ID
          'Lever Settings', // Title
          array( $this, 'print_section_info' ), // Callback
          'lever_jobs-settings-admin' // Page
      );

      add_settings_field(
          'site_url', // ID
          'Lever Site URL', // Title
          array( $this, 'lever_jobs_site_url_callback' ), // Callback
          'lever_jobs-settings-admin', // Page
          'lever_jobs_section' // Section
      );
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input ) {
      $new_input = array();
      if( isset( $input['site_url'] ) )
          $new_input['site_url'] = sanitize_text_field( $input['site_url'] );

      return $new_input;
    }

    /**
     * Print the Section text
     */
    public function print_section_info() {
      echo '<p>Enter your settings below:';
      echo '<br />and then use the <strong>[lever_job_listings]</strong> shortcode and / or the <strong>widget</strong> to display the content.</p>';
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function lever_jobs_site_url_callback() {
      printf(
          '<small>https://jobs.lever.co/</small><input type="text" id="site_url" class="narrow-fat" name="lever_jobs_settings[site_url]" value="%s" />',
          isset( $this->options['site_url'] ) ? esc_attr( $this->options['site_url']) : ''
      );
    }

    public function JobsShortCode($atts, $content = null) {

      if(isset($this->site_url) && $this->site_url != '') {
        $output = '';
        $positions = $this->get_lever_positions();
        $locations = $this->get_lever_locations();
        $teams = $this->get_lever_teams();
        $commitments = $this->get_lever_commitments();

        foreach ($teams as $team) {
          $output .= '<div class="job-section">';
          $output .= '<h3 class="title">'. ucwords($team) .'</h3>';
          $output .= '<ul class="job-listings">';

          foreach ($positions as $position) {
            if($position['team'] == $team) {
              $output .= '<li class="job-listing">';
              $output .= '<a class="posting-title" href="' . $position['hostedUrl'] . '">';
              $output .= '<h4>' . $position['title'] . '</h4>';
              $output .= '<div class="posting-categories">';
              $output .= '<span href="#" class="sort-by-location posting-category">' . $position['location'] . '</span>';
              $output .= '<span href="#" class="sort-by-team posting-category">' . $position['team'] . '</span>';
              $output .= '<span href="#" class="sort-by-commitment posting-category">' . $position['commitment'] . '</span>';
              $output .= '</div>';

              $output .= '</a>';

              $output .= '</li>';
            }
          }

          $output .= '</ul>';
          $output .= '</div>';
        }

        return $output;
      }

    }

    // Send Curl Request to Lever Endpoint and return the response
    public function sendRequest() {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl.$this->site_url);
      curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
      $response = json_decode(curl_exec($ch),true);
      return $response;
    }

    public function get_lever_positions() {
      // Get any existing copy of our transient data
      if ( false === ( $lever_data = get_transient( 'lever_positions' ) ) ) {
        // It wasn't there, so make a new API Request and regenerate the data
        $positions = $this->sendRequest();
        if( $positions != '' ) {
          $lever_data = array();

          foreach($positions as $item) {
            $lever_position = array(
              'id' => $item['id'],
              'title' => $item['text'],
              'location' => $item['categories']['location'],
              'commitment' => $item['categories']['commitment'],
              'team' => $item['categories']['team'],
              'description' => $item['descriptionPlain'],
              'lists' => $item['lists'],
              'additional' => $item['additional'],
              'hostedUrl' => $item['hostedUrl'],
              'applyUrl' => $item['applyUrl'],
              'createdAt' => $item['createdAt']
            );

            array_push($lever_data, $lever_position);
          }
        }
        // Cache the Response
        $this->storeLeverPostions($lever_data);
      } else {
        // Get any existing copy of our transient data
        $lever_data = unserialize(get_transient( 'lever_positions' ));
      }
      // Finally return the data
      return $lever_data;
    }

    public function get_lever_locations() {
      $locations = array();
      $positions = $this->get_lever_positions();

      foreach ($positions as $position) {
        $locations[]  = $position['location'];
      }

      $locations = array_unique($locations);
      sort($locations);

      return $locations;
    }

    public function get_lever_commitments() {
      $commitments = array();
      $positions = $this->get_lever_positions();

      foreach ($positions as $position) {
        $commitments[]  = $position['commitment'];
      }

      $commitments = array_unique($commitments);
      sort($commitments);

      return $commitments;
    }

    public function get_lever_teams() {
      $teams = array();
      $positions = $this->get_lever_positions();

      foreach ($positions as $position) {
        $teams[]  = $position['team'];
      }

      $teams = array_unique($teams);
      sort($teams);

      return $teams;
    }

    public function storeLeverPostions( $positions ) {
      // Get any existing copy of our transient data
      if ( false === ( $lever_data = get_transient( 'lever_positions' ) ) ) {
        // It wasn't there, so regenerate the data and save the transient for 12 hours
        $lever_data = serialize($positions);
        set_transient( 'lever_positions', $lever_data, 24 * HOUR_IN_SECONDS );
      }
    }

    public function flushStoredInformation() {
      //Delete transient to force a new pull from the API
      delete_transient( 'lever_positions' );
    }

    //Returns the url of the plugin's root folder
    protected function getBaseUrl() {
      return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function getBasePath() {
      $folder = basename(dirname(__FILE__));
      return WP_PLUGIN_DIR . "/" . $folder;
    }

  } //End Class

  /**
   * Instantiate this class to ensure the action and shortcode hooks are hooked.
   * This instantiation can only be done once (see it's __construct() to understand why.)
   */
  new Lever_Jobs_Plugin();

} // End if class exists statement

/**
* Ensure Widget class doesn't already exist
*/
if(! class_exists ("lever_jobs_Jobs_Widget") ) {

  add_action('widgets_init', create_function('', 'register_widget("lever_jobs_Jobs_Widget");'));
  class lever_jobs_Jobs_Widget extends WP_Widget {
    /**
     * Register widget with WordPress.
     */
    public function __construct() {
      parent::__construct('lever_jobs_jobs_widget', 'Lever Jobs Widget', array('description' => 'Lever is online software that helps companies post jobs online, manage applicants and hire great employees.') // Args
      );
    }

    /**
     * Front-end display of widget.
     *
     * @see WP_Widget::widget()
     *
     * @param array $args Widget arguments.
     * @param array $instance Saved values from database.
     */
    public function widget($args, $instance) {
      extract($args);
      $title = apply_filters('widget_title', $instance['title']);
      $site_url = $instance['site_url'];

      echo $before_widget;
      if (!empty($title)) { echo $before_title.$title.$after_title; }
      if($site_url != '') {
        echo '<!-- Lever Jobs Widget -->';
        echo '<script type="text/javascript">';
          echo 'var ht_settings = ( ht_settings || new Object() );';
          echo 'ht_settings.site_url = "'.$site_url.'";';
          echo 'ht_settings.src_code = "wordpress";';
        echo '</script>';
        echo '<script src="http://assets.Lever.com/javascripts/embed.js" type="text/javascript"></script>';
        echo '<div id="lever_jobs-jobs"></div>';
        echo '<link rel="stylesheet" type="text/css" media="all" href="http://assets.Lever.com/stylesheets/embed.css" />';
        echo '<!-- end Lever Jobs Widget -->';
      } else {
        echo '<p>Please Enter your Lever Account URL in the Widgets Section.</p>';
      }
      echo $after_widget;
    }

    /**
     * Sanitize widget form values as they are saved.
     *
     * @see WP_Widget::update()
     *
     * @param array $new_instance Values just sent to be saved.
     * @param array $old_instance Previously saved values from database.
     *
     * @return array Updated safe values to be saved.
     */
    public function update($new_instance, $old_instance) {
      $instance = array();
      $instance['title'] = strip_tags($new_instance['title']);
      $instance['site_url'] = strip_tags($new_instance['site_url']);
      return $instance;
    }

    /**
     * Back-end widget form.
     *
     * @see WP_Widget::form()
     *
     * @param array $instance Previously saved values from database.
     */
    public function form($instance) {
      if (isset($instance['title'])) {
        $title = $instance['title'];
      }
      if (isset($instance['site_url'])) {
        $site_url = $instance['site_url'];
      }
      echo '<p>';
        echo '<label for="'.$this->get_field_id('title').'">Title:</label>';
        echo '<input class="widefat" id="'.$this->get_field_id('title').'" name="'.$this->get_field_name('title').'" type="text" value="'.esc_attr($title).'" />';
      echo '</p>';
      echo '<p>';
        echo '<label for="'.$this->get_field_id('site_url').'">Lever Account URL</label><br />';
        echo '<small>http://</small><input class="narrowfat" id="'.$this->get_field_id('site_url').'" name="'.$this->get_field_name('site_url').'" type="text" value="'.esc_attr($site_url).'" /><small>.Lever.com</small>';
      echo '</p>';
    }
  }
} // End if class exists statement
