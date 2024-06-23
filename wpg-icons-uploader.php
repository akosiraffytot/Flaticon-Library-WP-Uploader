<?php
/*
Plugin Name: WPG Icons Library Uploader
Description: A plugin to upload and categorize icons from ZIP files with a progress bar.
Version: 1.9
Author: Rafael Mendoza
*/

if (!defined('ABSPATH')) {
    exit;
}

class WPGIconsUploader {

    private $batch_size = 1; // Number of PNG files to process in each batch

    public function __construct() {
        add_action('admin_menu', array($this, 'add_plugin_page'));
    }

    public function add_plugin_page() {
        add_menu_page(
            'WPG Icon\'s Uploader',
            'WPG Icon\'s Uploader',
            'manage_options',
            'wpg_icons_uploader',
            array($this, 'plugin_page_content'),
            'dashicons-format-gallery', // You can change the icon
            20
        );
    }

    public function plugin_page_content() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['zip_file'])) {
            $this->handle_zip_upload();
        }

        ?>
        <div class="wrap">
            <h2>WPG Icon's Uploader</h2>
            <form method="post" enctype="multipart/form-data">
                <label for="zip_file">Upload ZIP file:</label>
                <input type="file" name="zip_file" accept=".zip">
                <input type="submit" value="Upload" class="button button-primary">
            </form>
        </div>
        <?php
    }

    private function handle_zip_upload() {
        $uploaded_file = $_FILES['zip_file'];

        // Validate file type
        $file_extension = pathinfo($uploaded_file['name'], PATHINFO_EXTENSION);
        $file_path_name = pathinfo($uploaded_file['name'], PATHINFO_FILENAME);

        if ($file_extension !== 'zip') {
            echo '<div class="error"><p>Invalid file type. Please upload a ZIP file.</p></div>';
            return;
        }

        // Extract main name from file name
        $main_name = $this->extract_main_name($uploaded_file['name']);

        // Format category name
        $category_name = $this->format_category_name($main_name);

        // Create category if not exists
        $this->create_category($category_name);

        // Move the uploaded file to wp-content/uploads directory
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . $main_name;

        if (move_uploaded_file($uploaded_file['tmp_name'], $target_dir . '.zip')) {
            // Unzip the file
            $extracted_path = trailingslashit($upload_dir['basedir']);
            $this->unzip_file($target_dir . '.zip', $extracted_path);

            // Delete the uploaded zip file
            unlink($target_dir . '.zip');

            // Scan the 'png' folder and create posts in batches
            $png_folder_path = trailingslashit(WP_CONTENT_DIR . '/uploads/' . $file_path_name . '/png');
            $this->create_posts_in_batches($png_folder_path, $category_name, $file_path_name);

            echo '<div class="updated"><p>File uploaded, extracted, and posts created successfully!</p></div>';
        } else {
            echo '<div class="error"><p>Failed to upload the file.</p></div>';
        }
    }

    private function create_posts_in_batches($png_folder_path, $category_name, $file_path_name) {
        $png_files = glob($png_folder_path . '/*.png');

        $total_files = count($png_files);
        $batch_count = ceil($total_files / $this->batch_size);

        for ($i = 0; $i < $batch_count; $i++) {
            $start_index = $i * $this->batch_size;
            $batch_files = array_slice($png_files, $start_index, $this->batch_size);

            foreach ($batch_files as $png_file) {
                $file_name = pathinfo($png_file, PATHINFO_FILENAME);
                $post_title = $this->format_post_title($file_name);

                $category_object = get_category_by_slug($category_name);

                if ($category_object) {
                    $category_id = $category_object->term_id;

                    $new_post = array(
                        'post_title'    => $post_title,
                        'post_content'  => '', // You can customize the content if needed
                        'post_status'   => 'publish',
                        'post_type'     => 'post',
                        'post_category' => array($category_id),
                    );

                    $result = wp_insert_post($new_post);

                    if (!is_wp_error($result)) {
                        echo '<p>Post created: ' . $post_title . '</p>';
                        add_post_meta($result, 'wpg_icon_upload_directory', $file_path_name);
                        add_post_meta($result, 'wpg_icon_file_name', $file_name);

                        // Add tags based on synonyms
                        $this->add_tags_based_on_synonyms($result, $post_title);
                    } else {
                        echo '<p>Error creating post: ' . $post_title . '</p>';
                    }
                } else {
                    echo '<p>Error getting category ID for post: ' . $post_title . '</p>';
                }
            }
        }
    }

    private function extract_main_name($file_name) {
        $pattern = '/(\d+)-(.+)\.zip/';
        preg_match($pattern, $file_name, $matches);

        return isset($matches[2]) ? $matches[2] : '';
    }

    private function format_category_name($main_name) {
        return ucwords(str_replace('-', ' ', $main_name));
    }

    private function create_category($category_name) {
        if (!term_exists($category_name, 'category')) {
            wp_insert_term($category_name, 'category');
        }
    }

    private function unzip_file($zip_file, $extract_path) {
        $zip = new ZipArchive;
        if ($zip->open($zip_file) === TRUE) {
            $zip->extractTo($extract_path);
            $zip->close();
        }
    }

    private function format_post_title($file_name) {
        $remove_prefix = preg_replace('/^\d+-/', '', $file_name);
        $final_result = ucwords($remove_prefix);

        return $final_result;
    }

    private function add_tags_based_on_synonyms($post_id, $post_title) {
        $synonyms = $this->get_alternative_names_from_datamuse($post_title);
    
        if (!empty($synonyms)) {
            // Include the original post title as a tag
            $tags = array(sanitize_title($post_title));
    
            // Include synonyms as tags
            foreach ($synonyms as $synonym) {
                $tags[] = sanitize_title($synonym);
            }
    
            wp_set_post_tags($post_id, $tags, true);
        }
    }
    
    private function get_alternative_names_from_datamuse($post_title) {
        $words = explode(' ', $post_title);
        
        $alternative_names = array();
    
        foreach ($words as $word) {
            $url = 'https://api.datamuse.com/words?rel_trg=' . urlencode($word);
    
            $response = wp_remote_get($url);
    
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
    
                foreach ($data as $item) {
                    $alternative_names[] = $item['word'];
                }
            }
        }
    
        return $alternative_names;
    }    

    private function get_synonyms($word) {
        $url = "https://api.datamuse.com/words?rel_trg={$word}";

        $response = wp_safe_remote_get($url);

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (is_array($data)) {
                $synonyms = array_column($data, 'word');
                return $synonyms;
            }
        }

        return array();
    }
}

new WPGIconsUploader();
