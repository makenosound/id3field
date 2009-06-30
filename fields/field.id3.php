<?php

	ini_set("display_errors","2");
	ERROR_REPORTING(E_ALL);
	
	if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');
	
	require_once(TOOLKIT . '/fields/field.upload.php');
	
	class FieldID3 extends FieldUpload {
		protected $_driver = null;
		
	/*-------------------------------------------------------------------------
		Definition:
	-------------------------------------------------------------------------*/
		
		public function __construct(&$parent)
		{
			parent::__construct($parent);
			
			$this->_name = 'ID3';
			$this->_required = true;
			$this->_driver = $this->_engine->ExtensionManager->create('id3field');
			
			# Set defaults:
			$this->set('show_column', 'yes');
		}
		
		/*-------------------------------------------------------------------------
			Overrides:
		-------------------------------------------------------------------------*/	
	
		/**
		*		Override parent::createTable()
		*/
		function createTable() {
			$field_id = $this->get('id');
			
			return $this->_engine->Database->query("
				CREATE TABLE IF NOT EXISTS `tbl_entries_data_{$field_id}` (
					`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`entry_id` INT(11) UNSIGNED NOT NULL,
					`file` varchar(255) default NULL,
				  `size` int(11) unsigned NOT NULL,
				  `mimetype` varchar(50) default NULL,
				  `meta` varchar(255) default NULL,
					`title` varchar(255) NOT NULL,
					`artist` varchar(255) NOT NULL,
					`album` varchar(255) NOT NULL,
					`album_artist` varchar(255) NOT NULL,
					`comment` text,
					`genre` varchar(255) NOT NULL,
					`lyrics` text,
					`year` int(4),
					`duration` int(11),
					PRIMARY KEY  (`id`),
					KEY `entry_id` (`entry_id`),
					KEY `file` (`file`),
					KEY `mimetype` (`mimetype`)
				)
			");
		}
		
		
		/**
		*		Override parent::createTable()
		*/
		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = NULL)
		{
			$upload = parent::processRawFieldData($data, &$status, $simulate, $entry_id);
			$filepath = $upload['file'];
			## Figure out ID3
			$id3_data = $this->sort_id3_data();
			
			## Write out ID3 information
			$duration = $this->write_id3_tags($id3_data, $filepath);
			$id3_data['duration'] = $duration;
			
			$input = array_merge($upload, $id3_data);
			return $input;
		}
		
		/**
		*		Override parent::appendFormattedElement()
		*/
		function appendFormattedElement(&$wrapper, $data)
		{
			$item = new XMLElement($this->get('element_name'));
			
			$item->setAttributeArray(array(
				'size' => General::formatFilesize(filesize(WORKSPACE . $data['file'])),
			 	'path' => str_replace(WORKSPACE, NULL, dirname(WORKSPACE . $data['file'])),
				'type' => $data['mimetype'],
			));
			
			$item->appendChild(new XMLElement('filename', General::sanitize(basename($data['file']))));
						
			$m = unserialize($data['meta']);
			
			if(is_array($m) && !empty($m)){
				$item->appendChild(new XMLElement('meta', NULL, $m));
			}
			
			# ID3 data
			$tags = new XMLElement("id3-tags");
			$tags->setAttributeArray( 
				array(
					"duration"	=> $this->time_duration($data['duration'], NULL, true),
					"year"			=> $data['year'],
				)
			);
			$tags->appendChild(new XMLElement('title', $data['title']));
			$tags->appendChild(new XMLElement('artist', $data['artist']));
			$tags->appendChild(new XMLElement('album', $data['album']));
			$tags->appendChild(new XMLElement('album_artist', $data['album_artist']));
			$tags->appendChild(new XMLElement('genre', $data['genre']));
			$tags->appendChild(new XMLElement('comment', $data['comment'],
				array("word-count" => General::countWords($data['comment']))
			));
			$tags->appendChild(new XMLElement('lyrics', $data['lyrics'],
				array("word-count" => General::countWords($data['lyrics']))
			));	
			$item->appendChild($tags);
			$wrapper->appendChild($item);
		}
		
		/*-------------------------------------------------------------------------
			ID3 related:
		-------------------------------------------------------------------------*/
		private function sort_id3_data()
		{
			return array(
				'title'					=> "What up",
				'artist' 				=> "Max Wheeler",
				'album' 				=> "Album",
				'album_artist'	=> "National Museum of Australia",
				'genre' 				=> "Podcast",
				'year' 					=> 2009,
				'comment'				=> "<p>Donec mi placerat metus ac ornare semper.</p> <p>Magna <strong>eget</strong>, dui enim est.</p>",
				'lyrics'				=> "<p>Donec mi placerat metus ac ornare semper.</p> <p>Magna <strong>eget</strong>, dui enim est.</p>",
			);
		}
		
		private function write_id3_tags($tags_to_write, $file)
		{		
			$format = 'UTF-8';
			# Initialize getID3 engine
			require_once(EXTENSIONS . '/id3field/lib/getid3/getid3.php');
			$getID3 = new getID3;
			$getID3->setOption(array('encoding' => $format));

			# Initialize getID3 tag-writing module
			require_once(EXTENSIONS . '/id3field/lib/getid3/write.php');
			$writer = new getid3_writetags;
			$writer->filename		= WORKSPACE . $file;
			$writer->tagformats	= array('id3v1', 'id3v2.3');

			# Options (optional)
			$writer->overwrite_tags		 = true;
			$writer->tag_encoding 		 = $format;
			$writer->remove_other_tags = true;

			foreach ($tags_to_write as $key => $item)
			{
				if($key == "lyrics" OR $key == "comment") $item = strip_tags($item);
				$data[strtoupper($key)] = array((string) $item);
			}
			$writer->tag_data = $data;
			
			# Need to get the duration and pass it back
			
			# Write the tags
			if ($writer->WriteTags())
			{
				# $tagwriter->warnings
				return floor($writer->ThisFileInfo['playtime_seconds']);
			} else {
				return $tagwriter->errors;
			}
		}
		
		/*-------------------------------------------------------------------------
			Helpers
		-------------------------------------------------------------------------*/
		/**
		 * Return duration in seconds as hh:mm:ss format
		 */
		function time_duration($seconds)
		{
			# Define time periods
			$periods = array (
				'hours'     => 3600,
				'minutes'   => 60,
				'seconds'   => 1
				);
				
			# Break into periods
			$seconds = (float) $seconds;
			foreach ($periods as $period => $value) {
				$count = floor($seconds / $value);
				$segments[] = ($count < 10) ? "0" . (string) $count : $count;
				$seconds = $seconds % $value;
			}
			$str = implode(':', $segments);
			return $str;
		}
		
		
		/*-------------------------------------------------------------------------
			Setting Panel
		-------------------------------------------------------------------------*/
		
		/**
		*		Override parent::displaySettingsPanel()
		*/
		public function displaySettingsPanel(&$wrapper, $errors = null)
		{
			parent::displaySettingsPanel($wrapper, $errors);
			
			$order = $this->get('sortorder');
			
			$wrapper->appendChild(new XMLElement("h5", "ID3 tags"));
			
			$div = new XMLElement('div');
			$div->setAttribute('class', 'group');
			
			# Title
			$label = new XMLElement("label", "Title");
			$label->appendChild(Widget::Input("fields[$order][title]", $this->get('title'), 'text'));
			$div->appendChild($label);
			
			# Artist
			$label = new XMLElement("label", "Artist");
			$label->appendChild(Widget::Input("fields[$order][artist]", $this->get('artist'), 'text'));
			$div->appendChild($label);
			
			$wrapper->appendChild($div);
			
			$div = new XMLElement('div');
			$div->setAttribute('class', 'group');
			
			# Album
			$label = new XMLElement("label", "Album");
			$label->appendChild(Widget::Input("fields[$order][album]", $this->get('album'), 'text'));
			$div->appendChild($label);
			
			# Album Artist
			$label = new XMLElement("label", "Album Artist");
			$label->appendChild(Widget::Input("fields[$order][album_artist]", $this->get('album_artist'), 'text'));
			$div->appendChild($label);
			
			$wrapper->appendChild($div);
			
			$div = new XMLElement('div');
			$div->setAttribute('class', 'group');
			
			# Year
			$label = new XMLElement("label", "Year");
			$label->appendChild(Widget::Input("fields[$order][year]", $this->get('year'), 'text'));
			$div->appendChild($label);
			
			# Genre
			$label = new XMLElement("label", "Genre");
			$label->appendChild(Widget::Input("fields[$order][genre]", $this->get('genre'), 'text'));
			$div->appendChild($label);
			
			$wrapper->appendChild($div);
			
			$div = new XMLElement('div');
			$div->setAttribute('class', 'group');
			
			# Album
			$label = new XMLElement("label", "Lyrics");
			$label->appendChild(Widget::Input("fields[$order][lyrics]", $this->get('lyrics'), 'text'));
			$div->appendChild($label);
			
			# Album Artist
			$label = new XMLElement("label", "Comment");
			$label->appendChild(Widget::Input("fields[$order][comment]", $this->get('comment'), 'text'));
			$div->appendChild($label);
			
			$wrapper->appendChild($div);
		}
		
		
		public function commit()
		{
			if(!parent::commit()) return false;

			$id = $this->get('id');

			if($id === false) return false;

			$fields = array();

			$fields['field_id'] = $id;
			$fields['destination'] = $this->get('destination');
			$fields['validator'] = ($fields['validator'] == 'custom' ? NULL : $this->get('validator'));
			$fields['title'] = $this->get('title');
			$fields['artist'] = $this->get('artist');
			$fields['album'] = $this->get('album');
			$fields['album_artist'] = $this->get('album_artist');
			$fields['year'] = $this->get('year');
			$fields['genre'] = $this->get('genre');
			$fields['lyrics'] = $this->get('lyrics');
			$fields['comment'] = $this->get('comment');

			$this->_engine->Database->query("DELETE FROM `tbl_fields_".$this->handle()."` WHERE `field_id` = '$id' LIMIT 1");		
			return $this->_engine->Database->insert($fields, 'tbl_fields_' . $this->handle());
		}
	}