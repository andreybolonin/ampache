<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU General Public License, version 2 (GPLv2)
 * Copyright 2001 - 2014 Ampache.org
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License v2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

use MusicBrainz\MusicBrainz;
use MusicBrainz\Clients\RequestsMbClient;

/**
 * Art Class
 *
 * This class handles the images / artwork in ampache
 * This was initially in the album class, but was pulled out
 * to be more general and potentially apply to albums, artists, movies etc
 */
class Art extends database_object
{
    public $id;
    public $type;
    public $uid; // UID of the object not ID because it's not the ART.ID
    public $raw; // Raw art data
    public $raw_mime;

    public $thumb;
    public $thumb_mime;

    private static $enabled;

    /**
     * Constructor
     * Art constructor, takes the UID of the object and the
     * object type.
     */
    public function __construct($uid, $type = 'album')
    {
        $this->type = Art::validate_type($type);
        $this->uid = $uid;

    } // constructor

    /**
     * build_cache
     * This attempts to reduce # of queries by asking for everything in the
     * browse all at once and storing it in the cache, this can help if the
     * db connection is the slow point
     */
    public static function build_cache($object_ids)
    {
        if (!is_array($object_ids) || !count($object_ids)) { return false; }
        $uidlist = '(' . implode(',', $object_ids) . ')';
        $sql = "SELECT `object_type`, `object_id`, `mime`, `size` FROM `image` WHERE `object_id` IN $uidlist";
        $db_results = Dba::read($sql);

        while ($row = Dba::fetch_assoc($db_results)) {
            parent::add_to_cache('art', $row['object_type'] .
                $row['object_id'] . $row['size'], $row);
        }

        return true;

    } // build_cache

    /**
     * _auto_init
     * Called on creation of the class
     */
    public static function _auto_init()
    {
        if (!isset($_SESSION['art_enabled'])) {
            /*if (isset($_COOKIE['art_enabled'])) {
                $_SESSION['art_enabled'] = $_COOKIE['art_enabled'];
            } else {*/
                $_SESSION['art_enabled'] = true;
            //}
        }

        self::$enabled = make_bool($_SESSION['art_enabled']);
        //setcookie('art_enabled', self::$enabled, time() + 31536000, "/");
    }

    /**
     * is_enabled
     * Checks whether the user currently wants art
     */
    public static function is_enabled()
    {
        if (self::$enabled) {
            return true;
        }

        return false;
    }

    /**
     * set_enabled
     * Changes the value of enabled
     */
    public static function set_enabled($value = null)
    {
        if (is_null($value)) {
            self::$enabled = self::$enabled ? false : true;
        } else {
            self::$enabled = make_bool($value);
        }

        $_SESSION['art_enabled'] = self::$enabled;
        //setcookie('art_enabled', self::$enabled, time() + 31536000, "/");
    }

    /**
     * validate_type
     * This validates the type
     */
    public static function validate_type($type)
    {
        switch ($type) {
            case 'album':
            case 'artist':
            case 'video':
            case 'user':
            case 'tvshow':
            case 'tvshow_season':
                return $type;
            default:
                return 'album';
        }

    } // validate_type

    /**
     * extension
     * This returns the file extension for the currently loaded art
     */
    public static function extension($mime)
    {
        $data = explode("/", $mime);
        $extension = $data['1'];

        if ($extension == 'jpeg') { $extension = 'jpg'; }

        return $extension;

    } // extension

    /**
     * test_image
     * Runs some sanity checks on the putative image
     */
    public static function test_image($source)
    {
        if (strlen($source) < 10) {
            debug_event('Art', 'Invalid image passed', 1);
            return false;
        }

        // Check to make sure PHP:GD exists.  If so, we can sanity check
        // the image.
        if (function_exists('ImageCreateFromString')) {
             $image = ImageCreateFromString($source);
             if (!$image || imagesx($image) < 5 || imagesy($image) < 5) {
                debug_event('Art', 'Image failed PHP-GD test',1);
                return false;
            }
        }

        return true;
    } //test_image

    /**
     * get
     * This returns the art for our current object, this can
     * look in the database and will return the thumb if it
     * exists, if it doesn't depending on settings it will try
     * to create it.
     */
    public function get($raw=false)
    {
        // Get the data either way
        if (!$this->get_db()) {
            return false;
        }

        if ($raw || !$this->thumb) {
            return $this->raw;
        } else {
            return $this->thumb;
        }

    } // get


    /**
     * get_db
     * This pulls the information out from the database, depending
     * on if we want to resize and if there is not a thumbnail go
     * ahead and try to resize
     */
    public function get_db()
    {
        $sql = "SELECT `id`, `image`, `mime`, `size` FROM `image` WHERE `object_type` = ? AND `object_id` = ?";
        $db_results = Dba::read($sql, array($this->type, $this->uid));

        while ($results = Dba::fetch_assoc($db_results)) {
            if ($results['size'] == 'original') {
                $this->raw = $results['image'];
                $this->raw_mime = $results['mime'];
            } else if (AmpConfig::get('resize_images') &&
                    $results['size'] == '275x275') {
                $this->thumb = $results['image'];
                $this->raw_mime = $results['mime'];
            }
            $this->id = $results['id'];
        }
        // If we get nothing return false
        if (!$this->raw) { return false; }

        // If there is no thumb and we want thumbs
        if (!$this->thumb && AmpConfig::get('resize_images')) {
            $data = $this->generate_thumb($this->raw, array('width' => 275, 'height' => 275), $this->raw_mime);
            // If it works save it!
            if ($data) {
                $this->save_thumb($data['thumb'], $data['thumb_mime'], '275x275');
                $this->thumb = $data['thumb'];
                $this->thumb_mime = $data['thumb_mime'];
            } else {
                debug_event('Art','Unable to retrieve or generate thumbnail for ' . $this->type . '::' . $this->id,1);
            }
        } // if no thumb, but art and we want to resize

        return true;

    } // get_db

    public static function has_db($object_id, $object_type)
    {
        $sql = "SELECT COUNT(`id`) AS `nb_img` FROM `image` WHERE `object_type` = ? AND `object_id` = ?";
        $db_results = Dba::read($sql, array($object_type, $object_id));
        $nb_img = 0;
        if ($results = Dba::fetch_assoc($db_results)) {
            $nb_img = $results['nb_img'];
        }

        return ($nb_img > 0);
    }

    public function insert_url($url)
    {
        debug_event('art', 'Insert art from url ' . $url, '5');
        $image = Art::get_from_source(array('url' => $url), $this->type);
        $rurl = pathinfo($url);
        $mime = "image/" . $rurl['extension'];
        $this->insert($image, $mime);
    }

    /**
     * insert
     * This takes the string representation of an image and inserts it into
     * the database. You must also pass the mime type.
     */
    public function insert($source, $mime)
    {
        // Disabled in demo mode cause people suck and upload porn
        if (AmpConfig::get('demo_mode')) { return false; }

        // Check to make sure we like this image
        if (!self::test_image($source)) {
            debug_event('Art', 'Not inserting image, invalid data passed', 1);
            return false;
        }

        // Default to image/jpeg if they don't pass anything
        $mime = $mime ? $mime : 'image/jpeg';

        $image = Dba::escape($source);
        $mime = Dba::escape($mime);
        $uid = Dba::escape($this->uid);
        $type = Dba::escape($this->type);

        // Blow it away!
        $this->reset();

        // Insert it!
        $sql = "INSERT INTO `image` (`image`, `mime`, `size`, `object_type`, `object_id`) VALUES('$image', '$mime', 'original', '$type', '$uid')";
        Dba::write($sql);

        return true;

    } // insert

    /**
     * reset
     * This resets the art in the database
     */
    public function reset()
    {
        $sql = "DELETE FROM `image` WHERE `object_id` = ? AND `object_type` = ?";
        Dba::write($sql, array($this->uid, $this->type));
    } // reset

    /**
     * save_thumb
     * This saves the thumbnail that we're passed
     */
    public function save_thumb($source, $mime, $size)
    {
        // Quick sanity check
        if (!self::test_image($source)) {
            debug_event('Art', 'Not inserting thumbnail, invalid data passed', 1);
            return false;
        }

        $source = Dba::escape($source);
        $mime = Dba::escape($mime);
        $size = Dba::escape($size);
        $uid = Dba::escape($this->uid);
        $type = Dba::escape($this->type);

        $sql = "DELETE FROM `image` WHERE `object_id`='$uid' AND `object_type`='$type' AND `size`='$size'";
        Dba::write($sql);

        $sql = "INSERT INTO `image` (`image`, `mime`, `size`, `object_type`, `object_id`) VALUES('$source', '$mime', '$size', '$type', '$uid')";
        Dba::write($sql);
    } // save_thumb

    /**
     * get_thumb
     * Returns the specified resized image.  If the requested size doesn't
     * already exist, create and cache it.
     */
    public function get_thumb($size)
    {
        $sizetext = $size['width'] . 'x' . $size['height'];
        $sizetext = Dba::escape($sizetext);
        $type = Dba::escape($this->type);
        $uid = Dba::escape($this->uid);

        $sql = "SELECT `image`, `mime` FROM `image` WHERE `size`='$sizetext' AND `object_type`='$type' AND `object_id`='$uid'";
        $db_results = Dba::read($sql);

        $results = Dba::fetch_assoc($db_results);
        if (count($results)) {
            return array('thumb' => $results['image'],
                'thumb_mime' => $results['mime']);
        }

        // If we didn't get a result
        $results = $this->generate_thumb($this->raw, $size, $this->raw_mime);
        if ($results) {
            $this->save_thumb($results['thumb'], $results['thumb_mime'], $sizetext);
        }

        return $results;

    } // get_thumb

    /**
     * generate_thumb
     * Automatically resizes the image for thumbnail viewing.
     * Only works on gif/jpg/png/bmp. Fails if PHP-GD isn't available
     * or lacks support for the requested image type.
     */
    public function generate_thumb($image,$size,$mime)
    {
        $data = explode("/",$mime);
        $type = strtolower($data['1']);

        if (!self::test_image($image)) {
            debug_event('Art', 'Not trying to generate thumbnail, invalid data passed', 1);
            return false;
        }

        if (!function_exists('gd_info')) {
            debug_event('Art','PHP-GD Not found - unable to resize art',1);
            return false;
        }

        // Check and make sure we can resize what you've asked us to
        if (($type == 'jpg' OR $type == 'jpeg') AND !(imagetypes() & IMG_JPG)) {
            debug_event('Art','PHP-GD Does not support JPGs - unable to resize',1);
            return false;
        }
        if ($type == 'png' AND !imagetypes() & IMG_PNG) {
            debug_event('Art','PHP-GD Does not support PNGs - unable to resize',1);
            return false;
        }
        if ($type == 'gif' AND !imagetypes() & IMG_GIF) {
            debug_event('Art','PHP-GD Does not support GIFs - unable to resize',1);
            return false;
        }
        if ($type == 'bmp' AND !imagetypes() & IMG_WBMP) {
            debug_event('Art','PHP-GD Does not support BMPs - unable to resize',1);
            return false;
        }

        $source = imagecreatefromstring($image);

        if (!$source) {
            debug_event('Art','Failed to create Image from string - Source Image is damaged / malformed',1);
            return false;
        }

        $source_size = array('height' => imagesy($source), 'width' => imagesx($source));

        // Create a new blank image of the correct size
        $thumbnail = imagecreatetruecolor($size['width'], $size['height']);

        if (!imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $size['width'], $size['height'], $source_size['width'], $source_size['height'])) {
            debug_event('Art','Unable to create resized image',1);
            return false;
        }

        // Start output buffer
        ob_start();

        // Generate the image to our OB
        switch ($type) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($thumbnail, null, 75);
                $mime_type = image_type_to_mime_type(IMAGETYPE_JPEG);
            break;
            case 'gif':
                imagegif($thumbnail);
                $mime_type = image_type_to_mime_type(IMAGETYPE_GIF);
            break;
            // Turn bmps into pngs
            case 'bmp':
            case 'png':
                imagepng($thumbnail);
                $mime_type = image_type_to_mime_type(IMAGETYPE_PNG);
            break;
        } // resized

        if (!isset($mime_type)) {
            debug_event('Art', 'Eror: No mime type found.', 1);
            return false;
        }

        $data = ob_get_contents();
        ob_end_clean();

        if (!strlen($data)) {
            debug_event('Art', 'Unknown Error resizing art', 1);
            return false;
        }

        return array('thumb' => $data, 'thumb_mime' => $mime_type);

    } // generate_thumb

    /**
     * get_from_source
     * This gets an image for the album art from a source as
     * defined in the passed array. Because we don't know where
     * it's coming from we are a passed an array that can look like
     * ['url']      = URL *** OPTIONAL ***
     * ['file']     = FILENAME *** OPTIONAL ***
     * ['raw']      = Actual Image data, already captured
     */
    public static function get_from_source($data, $type = 'album')
    {
        // Already have the data, this often comes from id3tags
        if (isset($data['raw'])) {
            return $data['raw'];
        }

        // If it came from the database
        if (isset($data['db'])) {
            // Repull it
            $uid = Dba::escape($data['db']);
            $type = Dba::escape($type);

            $sql = "SELECT * FROM `image` WHERE `object_type`='$type' AND `object_id`='$uid' AND `size`='original'";
            $db_results = Dba::read($sql);
            $row = Dba::fetch_assoc($db_results);
            return $row['art'];
        } // came from the db

        // Check to see if it's a URL
        if (isset($data['url'])) {
            $options = array();
            if (AmpConfig::get('proxy_host') AND AmpConfig::get('proxy_port')) {
                $proxy = array();
                $proxy[] = AmpConfig::get('proxy_host') . ':' . AmpConfig::get('proxy_port');
                if (AmpConfig::get('proxy_user')) {
                    $proxy[] = AmpConfig::get('proxy_user');
                    $proxy[] = AmpConfig::get('proxy_pass');
                }
                $options['proxy'] = $proxy;
            }
            $request = Requests::get($data['url'], array(), $options);
            return $request->body;
        }

        // Check to see if it's a FILE
        if (isset($data['file'])) {
            $handle = fopen($data['file'],'rb');
            $image_data = fread($handle,filesize($data['file']));
            fclose($handle);
            return $image_data;
        }

        // Check to see if it is embedded in id3 of a song
        if (isset($data['song'])) {
            // If we find a good one, stop looking
            $getID3 = new getID3();
            $id3 = $getID3->analyze($data['song']);

            if ($id3['format_name'] == "WMA") {
                return $id3['asf']['extended_content_description_object']['content_descriptors']['13']['data'];
            } elseif (isset($id3['id3v2']['APIC'])) {
                // Foreach in case they have more then one
                foreach ($id3['id3v2']['APIC'] as $image) {
                    return $image['data'];
                }
            }
        } // if data song

        return false;

    } // get_from_source

    /**
     * url
     * This returns the constructed URL for the art in question
     */
    public static function url($uid,$type,$sid=false)
    {
        $sid = $sid ? scrub_out($sid) : scrub_out(session_id());
        $type = self::validate_type($type);

        $key = $type . $uid;

        if (parent::is_cached('art', $key . '275x275') && AmpConfig::get('resize_images')) {
            $row = parent::get_from_cache('art', $key . '275x275');
            $mime = $row['mime'];
        }
        if (parent::is_cached('art', $key . 'original')) {
            $row = parent::get_from_cache('art', $key . 'original');
            $thumb_mime = $row['mime'];
        }
        if (!isset($mime) && !isset($thumb_mime)) {
            $sql = "SELECT `object_type`, `object_id`, `mime`, `size` FROM `image` WHERE `object_type` = ? AND `object_id` = ?";
            $db_results = Dba::read($sql, array($type, $uid));

            while ($row = Dba::fetch_assoc($db_results)) {
                parent::add_to_cache('art', $key . $row['size'], $row);
                if ($row['size'] == 'original') {
                    $mime = $row['mime'];
                } else if ($row['size'] == '275x275' && AmpConfig::get('resize_images')) {
                    $thumb_mime = $row['mime'];
                }
            }
        }

        $mime = isset($thumb_mime) ? $thumb_mime : (isset($mime) ? $mime : null);
        $extension = self::extension($mime);

        if (AmpConfig::get('stream_beautiful_url')) {
            if (empty($extension)) {
                $extension = 'jpg';
            }
            $url = AmpConfig::get('web_path') . '/play/art/' . $sid . '/' . scrub_out($type) . '/' . scrub_out($uid) . '/thumb.' . $extension;
        } else {
            $url = AmpConfig::get('web_path') . '/image.php?object_id=' . scrub_out($uid) . '&object_type=' . scrub_out($type) . '&auth=' . $sid;
            if (!empty($extension)) {
                $name = 'art.' . $extension;
                $url .= '&name=' . $name;
            }
        }

        return $url;

    } // url

    /**
     * gc
     * This cleans up art that no longer has a corresponding object
     */
    public static function gc()
    {
        // iterate over our types and delete the images
        foreach (array('album', 'artist') as $type) {
            $sql = "DELETE FROM `image` USING `image` LEFT JOIN `" .
                $type . "` ON `" . $type . "`.`id`=" .
                "`image`.`object_id` WHERE `object_type`='" .
                $type . "' AND `" . $type . "`.`id` IS NULL";
            Dba::write($sql);
        } // foreach
    }

    /**
     * gather
     * This tries to get the art in question
     */
    public function gather($options = array(), $limit = false)
    {
        // Define vars
        $results = array();

        if (count($options) == 0) {
            switch ($this->type) {
                case 'album':
                    $album = new Album($this->uid);
                    $album->format();
                    $options['artist'] = $album->f_artist;
                    $options['album'] = $album->f_name;
                    $options['keyword'] = $options['artist'] . ' ' . $options['album'];
                break;
                case 'artist':
                    $artist = new Artist($this->uid);
                    $artist->format();
                    $options['artist'] = $album->f_artist;
                    $options['keyword'] = $options['artist'];
                break;
                case 'tvshow':
                    $tvshow = new TVShow($this->uid);
                    $tvshow->format();
                    $options['tvshow'] = $tvshow->f_name;
                    $options['keyword'] = $options['tvshow'];
                break;
                case 'tvshow_season':
                    $season = new TVShow_Season($this->uid);
                    $season->format();
                    $options['tvshow'] = $season->f_tvshow;
                    $options['tvshow_season'] = $season->f_name;
                    $options['keyword'] = $options['tvshow'];
                break;
                case 'tvshow_episode':
                    $video = new TVShow_Episode($this->uid);
                    $video->format();
                    $options['tvshow'] = $video->f_tvshow;
                    $options['tvshow_season'] = $video->f_tvshow_season;
                    $options['tvshow_episode'] = $video->episode_number;
                    $options['keyword'] = $options['tvshow'] . " " . $video->f_title;
                break;
                case 'video':
                case 'clip':
                case 'movie':
                case 'personal_video':
                    $video = new Video($this->uid);
                    $video->format();
                    $options['keyword'] = $video->f_title;
                break;
            }
        }

        $config = AmpConfig::get('art_order');
        $methods = get_class_methods('Art');

        /* If it's not set */
        if (empty($config)) {
            // They don't want art!
            debug_event('Art', 'art_order is empty, skipping art gathering', 3);
            return array();
        } elseif (!is_array($config)) {
            $config = array($config);
        }

        debug_event('Art','Searching using:' . json_encode($config), 3);

        $plugin_names = Plugin::get_plugins('gather_arts');
        foreach ($config as $method) {
            $method_name = "gather_" . $method;

            $data = array();
            if (in_array($method, $plugin_names)) {
                $plugin = new Plugin($method);
                $installed_version = Plugin::get_plugin_version($plugin->_plugin->name);
                if ($installed_version) {
                    if ($plugin->load($GLOBALS['user'])) {
                        $data = $plugin->_plugin->gather_arts($this->type, $options, $limit);
                    }
                }
            } else if (in_array($method_name, $methods)) {
                debug_event('Art', "Method used: $method_name", 3);
                // Some of these take options!
                switch ($method_name) {
                    case 'gather_amazon':
                        $data = $this->{$method_name}($limit, $options);
                    break;
                    case 'gather_lastfm':
                        $data = $this->{$method_name}($limit, $options);
                    break;
                    case 'gather_google':
                        $data = $this->{$method_name}($limit, $options);
                    break;
                    default:
                        $data = $this->{$method_name}($limit);
                    break;
                }
            } else {
                debug_event("Art", $method_name . " not defined", 1);
            }

            // Add the results we got to the current set
            $results = array_merge((array) $data, $results);

            if ($limit && count($results) >= $limit) {
                return array_slice($results, 0, $limit);
            }

        } // end foreach

        return $results;

    } // gather

    ///////////////////////////////////////////////////////////////////////
    // Art Methods
    ///////////////////////////////////////////////////////////////////////

    /**
     * gather_db
     * This function retrieves art that's already in the database
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function gather_db($limit = null)
    {
        if ($this->get_db()) {
            return array('db' => true);
        }
        return array();
    }

    /**
     * gather_musicbrainz
     * This function retrieves art based on MusicBrainz' Advanced
     * Relationships
     */
    public function gather_musicbrainz($limit = 5, $data = array())
    {
        $images    = array();
        $num_found = 0;

        if ($this->type != 'album') {
            return $images;
        }

        if ($data['mbid']) {
            debug_event('mbz-gatherart', "Album MBID: " . $data['mbid'], '5');
        } else {
            return $images;
        }

        $mb = new MusicBrainz(new RequestsMbClient());
        $includes = array(
            'url-rels'
        );
        try {
            $release = $mb->lookup('release', $data['mbid'], $includes);
        } catch (Exception $e) {
            return $images;
        }

        $asin = $release->asin;

        if ($asin) {
            debug_event('mbz-gatherart', "Found ASIN: " . $asin, '5');
            $base_urls = array(
                "01" => "ec1.images-amazon.com",
                "02" => "ec1.images-amazon.com",
                "03" => "ec2.images-amazon.com",
                "08" => "ec1.images-amazon.com",
                "09" => "ec1.images-amazon.com",
            );
            foreach ($base_urls as $server_num => $base_url) {
                // to avoid complicating things even further, we only look for large cover art
                $url = 'http://' . $base_url . '/images/P/' . $asin . '.' . $server_num . '.LZZZZZZZ.jpg';
                debug_event('mbz-gatherart', "Evaluating Amazon URL: " . $url, '5');
                $options = array();
                if (AmpConfig::get('proxy_host') AND AmpConfig::get('proxy_port')) {
                    $proxy = array();
                    $proxy[] = AmpConfig::get('proxy_host') . ':' . AmpConfig::get('proxy_port');
                    if (AmpConfig::get('proxy_user')) {
                        $proxy[] = AmpConfig::get('proxy_user');
                        $proxy[] = AmpConfig::get('proxy_pass');
                    }
                    $options['proxy'] = $proxy;
                }
                $request = Requests::get($url, array(), $options);
                if ($request->status_code == 200) {
                    $num_found++;
                    debug_event('mbz-gatherart', "Amazon URL added: " . $url, '5');
                    $images[] = array(
                        'url'  => $url,
                        'mime' => 'image/jpeg',
                    );
                    if ($num_found >= $limit) {
                        return $images;
                    }
                }
            }
        }
        // The next bit is based directly on the MusicBrainz server code
        // that displays cover art.
        // I'm leaving in the releaseuri info for the moment, though
        // it's not going to be used.
        $coverartsites = array();
        $coverartsites[] = array(
            'name' => "CD Baby",
            'domain' => "cdbaby.com",
            'regexp' => '@http://cdbaby\.com/cd/(\w)(\w)(\w*)@',
            'imguri' => 'http://cdbaby.name/$matches[1]/$matches[2]/$matches[1]$matches[2]$matches[3].jpg',
            'releaseuri' => 'http://cdbaby.com/cd/$matches[1]$matches[2]$matches[3]/from/musicbrainz',
        );
        $coverartsites[] = array(
            'name' => "CD Baby",
            'domain' => "cdbaby.name",
            'regexp' => "@http://cdbaby\.name/([a-z0-9])/([a-z0-9])/([A-Za-z0-9]*).jpg@",
            'imguri' => 'http://cdbaby.name/$matches[1]/$matches[2]/$matches[3].jpg',
            'releaseuri' => 'http://cdbaby.com/cd/$matches[3]/from/musicbrainz',
        );
        $coverartsites[] = array(
            'name' => 'archive.org',
            'domain' => 'archive.org',
            'regexp' => '/^(.*\.(jpg|jpeg|png|gif))$/',
            'imguri' => '$matches[1]',
            'releaseuri' => '',
        );
        $coverartsites[] = array(
            'name' => "Jamendo",
            'domain' => "www.jamendo.com",
            'regexp' => '/http://www\.jamendo\.com/(\w\w/)?album/(\d+)/',
            'imguri' => 'http://img.jamendo.com/albums/$matches[2]/covers/1.200.jpg',
            'releaseuri' => 'http://www.jamendo.com/album/$matches[2]',
        );
        $coverartsites[] = array(
            'name' => '8bitpeoples.com',
            'domain' => '8bitpeoples.com',
            'regexp' => '/^(.*)$/',
            'imguri' => '$matches[1]',
            'releaseuri' => '',
        );
        $coverartsites[] = array(
            'name' => 'Encyclopédisque',
            'domain' => 'encyclopedisque.fr',
            'regexp' => '/http://www.encyclopedisque.fr/images/imgdb/(thumb250|main)/(\d+).jpg/',
            'imguri' => 'http://www.encyclopedisque.fr/images/imgdb/thumb250/$matches[2].jpg',
            'releaseuri' => 'http://www.encyclopedisque.fr/',
        );
        $coverartsites[] = array(
            'name' => 'Thastrom',
            'domain' => 'www.thastrom.se',
            'regexp' => '/^(.*)$/',
            'imguri' => '$matches[1]',
            'releaseuri' => '',
        );
        $coverartsites[] = array(
            'name' => 'Universal Poplab',
            'domain' => 'www.universalpoplab.com',
            'regexp' => '/^(.*)$/',
            'imguri' => '$matches[1]',
            'releaseuri' => '',
        );
        foreach ($release->relations as $ar) {
            $arurl = $ar->url->resource;
            debug_event('mbz-gatherart', "Found URL AR: " . $arurl , '5');
            foreach ($coverartsites as $casite) {
                if (strpos($arurl, $casite['domain']) !== false) {
                    debug_event('mbz-gatherart', "Matched coverart site: " . $casite['name'], '5');
                    if (preg_match($casite['regexp'], $arurl, $matches)) {
                        $num_found++;
                        $url = '';
                        eval("\$url = \"$casite[imguri]\";");
                        debug_event('mbz-gatherart', "Generated URL added: " . $url, '5');
                        $images[] = array(
                            'url'  => $url,
                            'mime' => 'image/jpeg',
                        );
                        if ($num_found >= $limit) {
                            return $images;
                        }
                    }
                }
            } // end foreach coverart sites
        } // end foreach

        return $images;

    } // gather_musicbrainz

    /**
     * gather_folder
     * This returns the art from the folder of the files
     * If a limit is passed or the preferred filename is found the current
     * results set is returned
     */
    public function gather_folder($limit = 5)
    {
        $media = new Album($this->uid);
        $songs = $media->get_songs();
        $results = array();
        $preferred = false;
        // For storing which directories we've already done
        $processed = array();

        /* See if we are looking for a specific filename */
        $preferred_filename = AmpConfig::get('album_art_preferred_filename');

        // Array of valid extensions
        $image_extensions = array(
            'bmp',
            'gif',
            'jp2',
            'jpeg',
            'jpg',
            'png'
        );

        foreach ($songs as $song_id) {
            $song = new Song($song_id);
            $dir = dirname($song->file);

            if (isset($processed[$dir])) {
                continue;
            }

            debug_event('folder_art', "Opening $dir and checking for Album Art", 3);

            /* Open up the directory */
            $handle = opendir($dir);

            if (!$handle) {
                Error::add('general', T_('Error: Unable to open') . ' ' . $dir);
                debug_event('folder_art', "Error: Unable to open $dir for album art read", 2);
                continue;
            }

            $processed[$dir] = true;

            // Recurse through this dir and create the files array
            while ($file = readdir($handle)) {
                $extension = pathinfo($file);
                $extension = $extension['extension'];

                // Make sure it looks like an image file
                if (!in_array($extension, $image_extensions)) {
                    continue;
                }

                $full_filename = $dir . '/' . $file;

                // Make sure it's got something in it
                if (!filesize($full_filename)) {
                    debug_event('folder_art', "Empty file, rejecting $file", 5);
                    continue;
                }

                // Regularise for mime type
                if ($extension == 'jpg') {
                    $extension = 'jpeg';
                }

                // Take an md5sum so we don't show duplicate
                // files.
                $index = md5($full_filename);

                if ($file == $preferred_filename) {
                    // We found the preferred filename and
                    // so we're done.
                    debug_event('folder_art', "Found preferred image file: $file", 5);
                    $preferred[$index] = array(
                        'file' => $full_filename,
                        'mime' => 'image/' . $extension
                    );
                    break;
                }

                debug_event('folder_art', "Found image file: $file", 5);
                $results[$index] = array(
                    'file' => $full_filename,
                    'mime' => 'image/' . $extension
                );

            } // end while reading dir
            closedir($handle);

        } // end foreach songs

        if (is_array($preferred)) {
            // We found our favourite filename somewhere, so we need
            // to dump the other, less sexy ones.
            $results = $preferred;
        }

        debug_event('folder_art', 'Results: ' . json_encode($results), 5);
        if ($limit && count($results) > $limit) {
            $results = array_slice($results, 0, $limit);
        }

        return array_values($results);

    } // gather_folder

    /**
     * gather_tags
     * This looks for the art in the meta-tags of the file
     * itself
     */
    public function gather_tags($limit = 5)
    {
        // We need the filenames
        $album = new Album($this->uid);

        // grab the songs and define our results
        $songs = $album->get_songs();
        $data = array();

        // Foreach songs in this album
        foreach ($songs as $song_id) {
            $song = new Song($song_id);
            // If we find a good one, stop looking
            $getID3 = new getID3();
            try { $id3 = $getID3->analyze($song->file); } catch (Exception $error) {
                debug_event('getid3', $error->getMessage(), 1);
            }

            if (isset($id3['asf']['extended_content_description_object']['content_descriptors']['13'])) {
                $image = $id3['asf']['extended_content_description_object']['content_descriptors']['13'];
                $data[] = array(
                    'song' => $song->file,
                    'raw' => $image['data'],
                    'mime' => $image['mime']);
            }

            if (isset($id3['id3v2']['APIC'])) {
                // Foreach in case they have more then one
                foreach ($id3['id3v2']['APIC'] as $image) {
                    $data[] = array(
                        'song' => $song->file,
                        'raw' => $image['data'],
                        'mime' => $image['mime']);
                }
            }

            if ($limit && count($data) >= $limit) {
                return array_slice($data, 0, $limit);
            }

        } // end foreach

        return $data;

    } // gather_tags

    /**
     * gather_google
     * Raw google search to retrieve the art, not very reliable
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function gather_google($limit = 5, $data = array())
    {
        $images = array();
        $search = rawurlencode($data['keyword']);

        $size = '&imgsz=m'; // Medium
        //$size = '&imgsz=l'; // Large

        $url = "http://images.google.com/images?source=hp&q=" . $search . "&oq=&um=1&ie=UTF-8&sa=N&tab=wi&start=0&tbo=1" . $size;
        $html = file_get_contents($url);

        if(preg_match_all("|\ssrc\=\"(http.+?)\"|", $html, $matches, PREG_PATTERN_ORDER))
            foreach ($matches[1] as $match) {
                $extension = "image/jpeg";

                if (strrpos($extension, '.') !== false) $extension = substr($extension, strrpos($extension, '.') + 1);

                $images[] = array('url' => $match, 'mime' => $extension);
            }

        return $images;

    } // gather_google

    /**
     * gather_lastfm
     * This returns the art from lastfm. It doesn't currently require an
     * account but may in the future.
     */
    public function gather_lastfm($limit = 5, $data = array())
    {
        $images = array();

        if ($this->type != 'album' || empty($data['artist']) || empty($data['album'])) {
            return $images;
        }

        $xmldata = Recommendation::album_search($data['artist'], $data['album']);

        if (!count($xmldata)) { return array(); }

        $coverart = (array) $xmldata->coverart;
        if (!$coverart) { return array(); }

        ksort($coverart);
        foreach ($coverart as $url) {
            // We need to check the URL for the /noimage/ stuff
            if (strpos($url, '/noimage/') !== false) {
                debug_event('LastFM', 'Detected as noimage, skipped ' . $url, 3);
                continue;
            }

            // HACK: we shouldn't rely on the extension to determine file type
            $results = pathinfo($url);
            $mime = 'image/' . $results['extension'];
            $images[] = array('url' => $url, 'mime' => $mime);
            if ($limit && count($images) >= $limit) {
                return $images;
            }
        } // end foreach

        return $images;

    } // gather_lastfm

    public static function gather_metadata_plugin($plugin, $type, $options)
    {
        $gtypes = array();
        switch ($type) {
            case 'tvshow':
            case 'tvshow_season':
            case 'tvshow_episode':
                $gtypes[] = 'tvshow';
                $media_info = array(
                    'tvshow' => $options['tvshow'],
                    'tvshow_season' => $options['tvshow_season'],
                    'tvshow_episode' => $options['tvshow_episode'],
                );
            break;
            default:
                $gtypes[] = 'movie';
                $media_info = array(
                    'title' => $options['keyword'],
                );
            break;
        }

        $meta = $plugin->get_metadata($gtypes, $media_info);
        $images = array();
        switch ($type) {
            case 'tvshow':
                if ($meta['tvshow_art']) {
                    $url = $meta['tvshow_art'];
                    $ures = pathinfo($url);
                    $images[] = array('url' => $url, 'mime' => 'image/' . $ures['extension']);
                }
            break;
            case 'tvshow_season':
                if ($meta['tvshow_season_art']) {
                    $url = $meta['tvshow_season_art'];
                    $ures = pathinfo($url);
                    $images[] = array('url' => $url, 'mime' => 'image/' . $ures['extension']);
                }
            break;
            default:
                if ($meta['art']) {
                    $url = $meta['art'];
                    $ures = pathinfo($url);
                    $images[] = array('url' => $url, 'mime' => 'image/' . $ures['extension']);
                }
            break;
        }

        return $images;
    }

    public static function get_thumb_size($thumb)
    {
        switch ($thumb) {
            case '1':
                /* This is used by the now_playing stuff */
                $size['height'] = '75';
                $size['width']    = '75';
            break;
            case '2':
                $size['height']    = '128';
                $size['width']    = '128';
            break;
            case '3':
                /* This is used by the flash player */
                $size['height']    = '80';
                $size['width']    = '80';
            break;
            case '4':
                /* Web Player size */
                $size['height'] = 200;
                $size['width'] = 200; // 200px width, set via CSS
            break;
            case '5':
                /* Web Player size */
                $size['height'] = 32;
                $size['width'] = 32;
            break;
            case '6':
                /* Video browsing size */
                $size['height'] = 150;
                $size['width'] = 100;
            break;
            case '7':
                /* Video page size */
                $size['height'] = 300;
                $size['width'] = 200;
            break;
            default:
                $size['height'] = '275';
                $size['width']    = '275';
            break;
        }

        return $size;
    }

    public static function display($object_type, $object_id, $name, $thumb, $link = null)
    {
        $size = self::get_thumb_size($thumb);
        $prettyPhoto = ($link == null);
        if ($link == null) {
            $link = AmpConfig::get('web_path') . "/image.php?object_id=" . $object_id . "&object_type=" . $object_type . "&auth=" . session_id();
        }
        echo "<div class=\"item_art\">";
        echo "<a href=\"" . $link . "\" alt=\"" . $name . "\"";
        if ($prettyPhoto) {
            echo " rel=\"prettyPhoto\"";
        }
        echo ">";
        $imgurl = AmpConfig::get('web_path') . "/image.php?object_id=" . $object_id . "&object_type=" . $object_type . "&thumb=" . $thumb;
        echo "<img src=\"" . $imgurl . "\" alt=\"" . $name . "\" height=\"" . $size['height'] . "\" width=\"" . $size['width'] . "\" />";
        if ($prettyPhoto) {
            echo "<div class=\"item_art_actions\">";
            $burl = substr($_SERVER['REQUEST_URI'], strlen(AmpConfig::get('raw_web_path')) + 1);
            $burl = rawurlencode($burl);
            if ($GLOBALS['user']->has_access('25')) {
                echo "<a href=\"" . AmpConfig::get('web_path') . "/arts.php?action=find_art&object_type=" . $object_type . "&object_id=" . $object_id . "&burl=" . $burl . "\">";
                echo UI::get_icon('edit', T_('Edit/Find Art'));
                echo "</a>";
            }
            if ($GLOBALS['user']->has_access('75')) {
                echo "<a href=\"" . AmpConfig::get('web_path') . "/arts.php?action=clear_art&object_type=" . $object_type . "&object_id=" . $object_id . "&burl=" . $burl . "\" onclick=\"return confirm('" . T_('Do you really want to reset art?') . "');\">";
                echo UI::get_icon('delete', T_('Reset Art'));
                echo "</a>";
            }
            echo"</div>";
        }
        echo "</a>\n";
        echo "</div>";
    }

} // Art
