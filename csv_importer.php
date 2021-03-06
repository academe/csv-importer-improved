<?php
/*
Plugin Name: CSV Importer Improved
Description: Import data as posts from a CSV file.
Version: 0.6.1
Author: Jason judge, Denis Kobozev
*/

/**
 * LICENSE: The MIT License {{{
 *
 * Copyright (c) <2009> <Denis Kobozev>
 * Copyright (c) <2015> <Jason Judge>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @author    Denis Kobozev <d.v.kobozev@gmail.com>
 * @copyright 2009 Denis Kobozev
 * @license   The MIT License
 * }}}
 */

class Academe_CSVImporterImprovedPlugin {
    const TAX_NONE = false;
    const TAX_HIERARCHICAL = 'hierarchical';
    const TAX_FLAT = 'flat';

    public $defaults = array(
        'csv_post_id'         => null,
        'csv_post_title'      => null,
        'csv_post_post'       => null,
        'csv_post_type'       => null,
        'csv_post_excerpt'    => null,
        'csv_post_date'       => null,
        'csv_post_tags'       => null,
        'csv_post_categories' => null,
        'csv_post_author'     => null,
        'csv_post_slug'       => null,
        'csv_post_parent'     => 0,
    );

    public $log = array();

    protected $row_number = 0;

    protected $taxonomies;

    /**
     * Determine value of option $name from database, $default value or $params,
     * save it to the db if needed and return it.
     *
     * @param string $name
     * @param mixed  $default
     * @param array  $params
     * @return string
     */
    function process_option($name, $default, $params) {
        if (array_key_exists($name, $params)) {
            $value = stripslashes($params[$name]);
        } elseif (array_key_exists('_'.$name, $params)) {
            // unchecked checkbox value
            $value = stripslashes($params['_' . $name]);
        } else {
            $value = null;
        }

        $stored_value = get_option($name);

        if ($value == null) {
            if ($stored_value === false) {
                if (is_callable($default) &&
                    method_exists($default[0], $default[1])) {
                    $value = call_user_func($default);
                } else {
                    $value = $default;
                }
                add_option($name, $value);
            } else {
                $value = $stored_value;
            }
        } else {
            if ($stored_value === false) {
                add_option($name, $value);
            } elseif ($stored_value != $value) {
                update_option($name, $value);
            }
        }

        return $value;
    }

    /**
     * Plugin's interface
     *
     * @return void
     */
    function form() {
        $opt_draft = $this->process_option(
            'csv_importer_import_as_draft',
            'publish',
            $_POST
        );

        $opt_cat = $this->process_option('csv_importer_cat', 0, $_POST);

        if ('POST' == $_SERVER['REQUEST_METHOD']) {
            $this->post(compact('opt_draft', 'opt_cat'));
        }

        // form HTML {{{
?>

<div class="wrap">
    <h2>Import CSV</h2>

    <form class="add:the-list: validate" method="post" enctype="multipart/form-data">
        <!-- Import as draft -->
        <p>
            <input name="_csv_importer_import_as_draft" type="hidden" value="publish" />
            <label>
                <input name="csv_importer_import_as_draft" type="checkbox" <?php if ('draft' == $opt_draft) { echo 'checked="checked"'; } ?> value="draft" /> <?php _e('Import new posts as drafts', 'csv-importer-improved') ?>
            </label>
        </p>

        <!-- Parent category -->
        <p>
            <?php _e('Organize into category', 'csv-importer-improved') ?>
            <?php wp_dropdown_categories(array('show_option_all' => __('No category', 'csv-importer-improved'), 'hide_empty' => 0, 'hierarchical' => 1, 'show_count' => 0, 'name' => 'csv_importer_cat', 'orderby' => 'name', 'selected' => $opt_cat));?>
            <br />
            <small>
                <?php _e('This will create new categories inside the category parent you choose.', 'csv-importer-improved') ?>
            </small>
        </p>

        <!-- File input -->
        <p>
            <label for="csv_import"><?php _e('Upload file:', 'csv-importer-improved') ?></label><br />
            <input name="csv_import" id="csv_import" type="file" value="" aria-required="true" />
        </p>
        <p class="submit">
            <input type="submit" class="button" name="submit" value="<?php _e('Import', 'csv-importer-improved') ?>" />
        </p>
    </form>
</div><!-- end wrap -->

<?php
        // end form HTML }}}
    }

    function print_messages() {
        if (!empty($this->log)) {

            // messages HTML {{{
?>

<div class="wrap">
    <?php if (!empty($this->log['error'])): ?>

    <div class="error">

        <?php foreach ($this->log['error'] as $error): ?>
            <p><?php echo $error; ?></p>
        <?php endforeach; ?>

    </div>

    <?php endif; ?>

    <?php if (!empty($this->log['notice'])): ?>

    <div class="updated fade">

        <?php foreach ($this->log['notice'] as $notice): ?>
            <p><?php echo $notice; ?></p>
        <?php endforeach; ?>

    </div>

    <?php endif; ?>
</div><!-- end wrap -->

<?php
            // end messages HTML }}}

            $this->log = array();
        }
    }

    /**
     * Handle POST submission.
     *
     * @param array $options
     * @return void
     */
    function post($options) {
        if (empty($_FILES['csv_import']['tmp_name'])) {
            $this->log('error', 'No file uploaded.');
            $this->print_messages();
            return;
        }

        if (! current_user_can('publish_pages') || ! current_user_can('publish_posts')) {
            $this->log(
                'error',
                'You do not have the permissions to publish posts and pages.'
            );
            $this->print_messages();
            return;
        }

        require_once 'File_CSV_DataSource/DataSource.php';

        $time_start = microtime(true);
        $csv = new File_CSV_DataSource;
        $file = $_FILES['csv_import']['tmp_name'];

        $this->stripBOM($file);

        if (! $csv->load($file)) {
            $this->log('error', 'Failed to load file, aborting.');
            $this->print_messages();
            return;
        }

        // Pad shorter rows with empty values.
        $csv->symmetrize();

        // WordPress sets the correct timezone for date functions somewhere
        // in the bowels of wp_insert_post(). We need strtotime() to return
        // correct time before the call to wp_insert_post().

        $tz = get_option('timezone_string');

        if ($tz && function_exists('date_default_timezone_set')) {
            date_default_timezone_set($tz);
        }

        $skipped = 0;
        $imported = 0;
        $comments = 0;

        foreach ($csv->connect() as $csv_data) {
            // As soon as the row number is non-zero, it will be added to the log entries.
            $this->row_number++;

            if ($post_id = $this->create_or_update_post($csv_data, $options)) {
                $imported++;
                $comments += $this->add_comments($post_id, $csv_data);
                $this->create_custom_fields($post_id, $csv_data);
            } else {
                $skipped++;
            }
        }

        // Reset the row number so it does not get reported with final notifications.
        $this->row_number = 0;

        if (file_exists($file)) {
            @unlink($file);
        }

        $exec_time = microtime(true) - $time_start;

        if ($skipped) {
            $this->log(
                'notice',
                'Skipped %d posts (most likely due to empty title, body and excerpt).',
                $skipped
            );
        }

        $this->log(
            'notice',
            'Imported %d posts and %d comments in %.2f seconds.',
            $imported,
            $comments,
            $exec_time
        );

        $this->print_messages();
    }

    /**
     * Support create or update post.
     * Update supported if an ID is supplied and it matches an existing post.
     */
    function create_or_update_post($data, $options) {
        $opt_draft = isset($options['opt_draft']) ? $options['opt_draft'] : null;
        $opt_cat = isset($options['opt_cat']) ? $options['opt_cat'] : null;

        $data = array_merge($this->defaults, $data);
        $type = $data['csv_post_type'] ? $data['csv_post_type'] : 'post';

        // Is this a valid post type?

        $valid_type = (function_exists('post_type_exists') && post_type_exists($type))
            || in_array($type, array('post', 'page'));

        if (! $valid_type) {
            $this->log('error', 'Unknown post type "%s".', $type);

            return;
        }

        // If we have an existigng ID, then we will be wanting to update a post.
        $existing_id = isset($data['csv_post_id']) ? convert_chars(trim($data['csv_post_id'])) : null;

        // If updating, we only want to set what we are given.
        // If creating, then set everything we can.

        $new_post = array(
            'post_type' => $type,
            'tax_input' => $this->get_taxonomies($data),
        );

        $x = $this->create_or_get_taxonomies($data); //var_dump($x); return;

        if (isset($existing_id)) {
            $new_post['ID'] = $existing_id;
        }

        // If updating, then only set the non-null attributes.

        if (! isset($existing_id) || isset($data['csv_post_title'])) {
            $new_post['post_title'] = convert_chars($data['csv_post_title']);
        }

        if (! isset($existing_id) || isset($data['csv_post_post'])) {
            $new_post['post_content'] = wpautop(convert_chars($data['csv_post_post']));
        }

        // The rule is:
        // * A new post must always have its status set.
        // * An existing post must have its status set only if overridden.
        // * A status in the CSV data will override the "import new posts as drafts" checkbox (i.e. $opt_draft).

        if (! isset($existing_id)) {
            // No post ID, so is a new post, so default the status to the
            // requested value ('published' or 'draft').

            $new_post['post_status'] = $opt_draft;
        }

        if (isset($data['csv_post_status']) && $data['csv_post_status'] != '') {
            // A status has been given in the data, so use that (overriding the
            // default status if necessary).

            $new_post['post_status'] = $data['csv_post_status'];
        }

        if (! isset($existing_id) || isset($data['csv_post_date'])) {
            $new_post['post_date'] = $this->parse_date($data['csv_post_date']);
        }

        if (! isset($existing_id) || isset($data['csv_post_excerpt'])) {
            $new_post['post_excerpt'] = convert_chars($data['csv_post_excerpt']);
        }

        if (!isset($existing_id) || isset($data['csv_post_slug'])) {
            $new_post['post_name'] = $data['csv_post_slug'];
        }

        if (!isset($existing_id) || isset($data['csv_post_author'])) {
            $new_post['post_author'] = $this->get_author_id($data['csv_post_author']);
        }

        if (!isset($existing_id) || isset($data['csv_post_parent'])) {
            $new_post['post_parent'] = $data['csv_post_parent'];
        }

        // Pages don't have tags or categories.

        if ($type !== 'page') {
            $new_post['tags_input'] = $data['csv_post_tags'];

            // Set up categories before inserting.
            $categories = $this->create_or_get_categories($data, $opt_cat);
            $new_post['post_category'] = $categories['all'];
        }

        // Collect together just the non-null post fields, those that have a value set,
        // even if an empty string.

        $set_post_fields = array();
        foreach($new_post as $name => $value) {
            if ($value !== null) {
                $set_post_fields[$name] = $value;
            }
        }

        if (! empty($existing_id)) {
            // Check that the existing post is of the correct type.
            $existing_post_type = get_post_type($existing_id);

            if (! $existing_post_type) {
                $this->log('error', 'Post %d to update does not exist.', $existing_id);

                return;
            }

            if ($existing_post_type != $type) {
                $this->log(
                    'error',
                    'Post %d to update is type "%s" but we are expecting "%s".',
                    $existing_id,
                    $existing_post_type,
                    $type
                );
            } else {
                // Update!
                $id = wp_update_post($set_post_fields);
            }
        } else {
            // Create!
            $id = wp_insert_post($set_post_fields);
        }

        // Clean up created categories on failure.
        // TODO: extend this to all taxonomies, and also when updating a post.
        if ($type !== 'page' && empty($id) && empty($existing_id)) {
            foreach ($categories['created'] as $term_id) {
                wp_delete_term($term_id, 'category');
            }
        }

        return $id;
    }

    /**
     * Return an array of category ids for a row, creating any categories necessary.
     * Categories are always hierarchical.
     *
     * @param string $data csv_post_categories cell contents
     * @param integer $common_parent_id common parent id for all categories
     * @return array category ids
     */
    function create_or_get_categories($data, $common_parent_id)
    {
        // "all" is all the category IDs for the data row.
        // The "created" categories are those we needed to create.
        $ids = array(
            'all' => array(),
            'created' => array(),
        );

        // Split into multiple categories by ",".
        $items = array_map('trim', explode(',', $data['csv_post_categories']));

        foreach ($items as $item) {
            // Each category can be supplied as a numeric ID or a name.
            // The CSV item can contained mixed names and IDs.

            if (is_numeric($item)) {
                if (get_category($item) !== null) {
                    $ids['all'][] = $item;
                } else {
                    $this->log('error', 'Category ID %s does not exist, skipping.', $item);
                }
            } else {
                $parent_id = $common_parent_id;

                // Item can be a single category name or a string such as
                // Parent > Child > Grandchild

                $categories = array_map('trim', explode('>', $item));

                // FIXME: we really need to check every level of the categories string (1>2>3 etc)
                // to ensure any numeric term exists. It is not sufficient to just check the top
                // level parent.

                if (count($categories) > 1 && is_numeric($categories[0])) {
                    $parent_id = $categories[0];

                    if (get_category($parent_id) !== null) {
                        // Valid id, everything's ok (not quite - see FIXME above).
                        $categories = array_slice($categories, 1);
                    } else {
                        $this->log('error', 'Category ID %s does not exist, skipping.', $parent_id);
                        continue;
                    }
                }

                $term_id = null;

                foreach ($categories as $category) {
                    if ($category) {
                        $term = term_exists($category, 'category', $parent_id);

                        if ($term) {
                            $term_id = $term['term_id'];
                        } else {
                            $term_id = wp_insert_category(array(
                                'cat_name' => $category,
                                'category_parent' => $parent_id,
                            ));
                            $ids['created'][] = $term_id;
                        }

                        $parent_id = $term_id;
                    }
                }

                // Only the ID of the last term in the hierarchy of terms is added to the post.
                if (isset($term_id)) {
                    $ids['all'][] = $term_id;
                }
            }
        }

        return $ids;
    }

    /**
     * Scan the data row for custom taxonimies, and return the list of
     * IDs for each taxonomy.
     * Any taxonomies that need to be created, will be created.
     * A taxonomy term can be supplied as an hierarchical string "A > 123 > Foo"
     * whether the underlying taxonomy is hiearchical or not. If the taxonomy
     * is not hiearchical, then only the last term will be used.
     * Numeric terms will be treated as taxonomy term IDs.
     * The return value will be an array indexed by taxonomy name. Each element
     * will be an array containing two arrays of IDs - 'all' and 'new'.
     */
    function create_or_get_taxonomies($row, $common_parent_id = 0)
    {
        // Prefix for the taxonomy column name.
        $prefix = 'csv_ctax_';

        $taxonomies = array(
            'all' => array(),
            'new' => array(),
        );

        // Get the list of taxonomies once, then work from that list.
        // This saves fetching the taxonomy details from the database over and over.
        // It also means taxonomy errors will only be reported once, for the first row.

        if ($this->taxonomies === null) {
            $this->taxonomies = array();

            foreach ($row as $column_name => $column_value) {
                if (strpos($column_name, $prefix) !== 0 || strlen($column_name) <= strlen($prefix)) {
                    // Not a recognised taxonomy column.
                    continue;
                }

                // Everything after the prefix.
                $taxonomy_name = substr($column_name, strlen($prefix));

                if (! taxonomy_exists($taxonomy_name)) {
                    $this->log('error', 'Unknown taxonomy "%s". Skipping this column.', $taxonomy_name);
                    continue;
                }

                // Now we have a valid taxonomy.
                $this->taxonomies[$taxonomy_name] = is_taxonomy_hierarchical($taxonomy_name)
                    ? static::TAX_HIERARCHICAL
                    : static::TAX_FLAT;
            }
        }

        foreach($this->taxonomies as $column_name => $structure_type) {
            $column_value = $row[$prefix . $column_name];

            if ($column_value === null) {
                // No terms for this row.
                continue;
            }

            // The data value is a CSV or newline list of categories.
            // A "\" will escape a comma if needed in a taxonomy name.

            $category_items = array_map(
                'trim',
                str_replace(
                    '\\,',
                    ',',
                    preg_split('/((?<!\\\\),|\r\n|\n|\r)/', $column_value)
                )
            );

            // Now each category item must be processed.
            foreach($category_items as $category_item) {
                // Split into levels (A>B>C).
                // There may just be one level.
                $levels = array_map('trim', explode('>', $category_item));

                // If the taxonomy is flat, then discard all but the last level.
                if ($structure_type == static::TAX_FLAT) {
                    $levels = end($levels);
                }

                // Go through the levels, making sure they are a valid hierarchy,
                // and that all numeric IDs match existing terms.
                // Create any terms that need creating on the way.
            }
        }

        return $taxonomies;
    }

    /**
     * Parse taxonomy data from the file.
     *
     * array(
     *      // hierarchical taxonomy name => ID array
     *      'my taxonomy 1' => array(1, 2, 3, ...),
     *      // non-hierarchical taxonomy name => term names string
     *      'my taxonomy 2' => array('term1', 'term2', ...),
     * )
     *
     * @param array $data
     * @return array
     */
    function get_taxonomies($data)
    {
        $taxonomies = array();

        foreach ($data as $name => $value) {
            if (preg_match('/^csv_ctax_(.+)$/', $name, $matches)) {
                $taxonomy_name = $matches[1];

                if (taxonomy_exists($taxonomy_name)) {
                    $taxonomies[$taxonomy_name] = $this->create_terms($taxonomy_name, $value);
                } else {
                    $this->log('error', 'Unknown taxonomy %s', $taxonomy_name);
                }
            }
        }

        return $taxonomies;
    }

    /**
     * Return an array of term IDs for hierarchical taxonomies or the original
     * string from CSV for non-hierarchical taxonomies. The original string
     * should have the same format as csv_post_tags.
     *
     * @param string $taxonomy_name
     * @param string $field
     * @return mixed
     */
    function create_terms($taxonomy_name, $field) {
        if (is_taxonomy_hierarchical($taxonomy_name)) {
            $term_ids = array();

            foreach ($this->_parse_tax($field) as $row) {
                list($parent, $child) = $row; // FIXME: Notice: Undefined offset: 1
                $parent_ok = true;

                if ($parent) {
                    $parent_info = term_exists($parent, $taxonomy_name);

                    if (! $parent_info) {
                        // create parent
                        $parent_info = wp_insert_term($parent, $taxonomy_name);
                    }

                    if (! is_wp_error($parent_info)) {
                        $parent_id = $parent_info['term_id'];
                    } else {
                        // could not find or create parent
                        $parent_ok = false;
                    }
                } else {
                    $parent_id = 0;
                }

                if ($parent_ok) {
                    $child_info = term_exists($child, $taxonomy_name, $parent_id);

                    if (! $child_info) {
                        // Create child.
                        $child_info = wp_insert_term(
                            $child,
                            $taxonomy_name,
                            array('parent' => $parent_id)
                        );
                    }

                    if (! is_wp_error($child_info)) {
                        $term_ids[] = $child_info['term_id'];
                    }
                }
            }

            return $term_ids;
        } else {
            return $field;
        }
    }

    /**
     * Hierarchical taxonomy fields are tiny CSV files in their own right.
     *
     * @param string $field
     * @return array
     */
    function _parse_tax($field) {
        $data = array();

        $lines = $this->split_lines($field);

        foreach ($lines as $line) {
            $data[] = str_getcsv($line, ',', '"');
        }

        return $data;
    }

    /**
     * Try to split lines of text correctly regardless of the platform the text
     * is coming from.
     */
    function split_lines($text) {
        $lines = preg_split("/(\r\n|\n|\r)/", $text);
        return $lines;
    }

    function add_comments($post_id, $data) {
        // First get a list of the comments for this post

        $comments = array();

        foreach ($data as $k => $v) {
            // comments start with cvs_comment_
            if (preg_match('/^csv_comment_([^_]+)_(.*)/', $k, $matches) && $v != '') {
                $comments[$matches[1]] = 1;
            }
        }

        // Sort this list which specifies the order they are inserted, in case
        // that matters somewhere

        ksort($comments);

        // Now go through each comment and insert it. More fields are possible
        // in principle (see docu of wp_insert_comment), but I didn't have data
        // for them so I didn't test them, so I didn't include them.
        $count = 0;

        foreach ($comments as $cid => $v) {
            $new_comment = array(
                'comment_post_ID' => $post_id,
                'comment_approved' => 1,
            );

            if (isset($data["csv_comment_{$cid}_author"])) {
                $new_comment['comment_author'] = convert_chars(
                    $data["csv_comment_{$cid}_author"]
                );
            }

            if (isset($data["csv_comment_{$cid}_author_email"])) {
                $new_comment['comment_author_email'] = convert_chars(
                    $data["csv_comment_{$cid}_author_email"]
                );
            }

            if (isset($data["csv_comment_{$cid}_url"])) {
                $new_comment['comment_author_url'] = convert_chars(
                    $data["csv_comment_{$cid}_url"]
                );
            }

            if (isset($data["csv_comment_{$cid}_content"])) {
                $new_comment['comment_content'] = convert_chars(
                    $data["csv_comment_{$cid}_content"]
                );
            }

            if (isset($data["csv_comment_{$cid}_date"])) {
                $new_comment['comment_date'] = $this->parse_date(
                    $data["csv_comment_{$cid}_date"]);
            }

            $id = wp_insert_comment($new_comment);

            if ($id) {
                $count++;
            } else {
                $this->log('error', 'Could not add comment %d', $cid);
            }
        }

        return $count;
    }

    function create_custom_fields($post_id, $data) {
        foreach ($data as $k => $v) {
            // anything that doesn't start with csv_ is a custom field
            if (! preg_match('/^csv_/', $k) && $v != '') {
                update_post_meta($post_id, $k, $v);
            }
        }
    }

    function get_author_id($author) {
        if (is_numeric($author)) {
            return $author;
        }

        $author_data = get_user_by('login', $author);

        return ($author_data) ? $author_data->ID : 0;
    }

    /**
     * Convert date in CSV file to 1999-12-31 23:52:00 format
     *
     * @param string $data
     * @return string
     */
    function parse_date($data) {
        $timestamp = strtotime($data);

        if ($timestamp === false) {
            return '';
        } else {
            return date('Y-m-d H:i:s', $timestamp);
        }
    }

    /**
     * Delete BOM from UTF-8 file.
     * This seems like a really clumsy way to do it - read the file into
     * memory, remove three bytes, then wite it back to disk. We should be
     * stripping out the BOM simply reading the file as a stream.
     *
     * @param string $fname
     * @return void
     */
    function stripBOM($fname) {
        $res = fopen($fname, 'rb');

        if ($res !== false) {
            $bytes = fread($res, 3);

            // UTF-8 BOM
            if ($bytes == pack('CCC', 0xef, 0xbb, 0xbf)) {
                $this->log('notice', 'Removing byte order mark.');
                fclose($res);

                $contents = file_get_contents($fname);

                if ($contents === false) {
                    trigger_error(__('Failed to get file contents.', 'csv-importer-improved'), E_USER_WARNING);
                }

                $contents = substr($contents, 3);
                $success = file_put_contents($fname, $contents);

                if ($success === false) {
                    trigger_error(__('Failed to put file contents.', 'csv-importer-improved'), E_USER_WARNING);
                }
            } else {
                fclose($res);
            }
        } else {
            $this->log('error', 'Failed to open file, aborting.');
        }
    }

    /**
     * Log an error or notice message.
     * Translate the message and support substitution placeholders.
     * Takes additional parameters as replacement strings for the placeholders.
     * Prefixes the message with the source row number, if known.
     */
    function log($level, $message)
    {
        // Capture additional arguments.
        $args = func_get_args();

        // Shift off "level" and "message".
        // This may leave an empty array if there were just two arguments.
        array_shift($args);
        array_shift($args);

        // If we have a row number, then use it to prefix the message.
        if (! empty($this->row_number)) {
            $prefix = sprintf(__('Row %d: ', 'csv-importer-improved'), $this->row_number);
        } else {
            $prefix = '';
        }

        $this->log[$level][] = vsprintf($prefix . __($message, 'csv-importer-improved'), $args);
    }
}


function csv_importer_improved_admin_menu() {
    require_once ABSPATH . '/wp-admin/admin.php';
    $plugin = new Academe_CSVImporterImprovedPlugin;

    add_management_page(
        'edit.php', 'CSV Importer Improved', 'manage_options', __FILE__,
        array($plugin, 'form')
    );
}

add_action('admin_menu', 'csv_importer_improved_admin_menu');
