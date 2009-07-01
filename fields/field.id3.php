<?php
	
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
		
		
		/**
		*		Override parent::processRawFieldData()
		*/
		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = NULL)
		{	
			## Figure out ID3
			$id3_data = $this->sort_id3_data();
			if (is_array($data) AND isset($data['name']) AND isset($id3_data['filename']))
			{
				$data['name'] = Lang::createFilename($id3_data['filename']) . "." . end(explode(".", $data['name']));
			}
			unset($id3_data['filename']);
		
			$upload = parent::processRawFieldData($data, &$status, $simulate, $entry_id);
			$filepath = $upload['file'];
			
			## Write out ID3 information
			$duration = $this->write_id3_tags($id3_data, $filepath);
			$id3_data['duration'] = $duration;
			
			$input = array_merge($upload, $id3_data);
			return $input;
		}
		
		
		/*-------------------------------------------------------------------------
			ID3 related:
		-------------------------------------------------------------------------*/
		private function sort_id3_data()
		{
			$id3_fields = array(
				'title',
				'artist',
				'album',
				'album_artist',
				'genre',
				'year',
				'comment',
				'lyrics',
				'filename',
			);
			$rules = $this->get();
			$fields = $_POST['fields'];

			foreach ($rules as $key => $rule)
			{
				if ( ! in_array($key, $id3_fields))
				{
					unset($rules[$key]);
					continue;
				}
				$content = $rules[$key];
				$replacements = array();

				# Find queries:
				preg_match_all('/\{[^\}]+\}/', $rule, $matches);
				foreach($matches[0] as $match)
				{
					$replacements[$match] = $fields[trim($match, '{}')];
				}
				if ( ! isset($replacements[$match])) $replacements[$match] = $rule;
				$content = str_replace(
					array_keys($replacements),
					array_values($replacements),
					$content
				);
				$rules[$key] = $content;
			}
			
			return $rules;
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
			
			# Filename
			$label = new XMLElement("label", "File Name");
			$label->appendChild(Widget::Input("fields[$order][filename]", $this->get('filename'), 'text'));
			$wrapper->appendChild($label);
		}
		
		/**
		*		Override parent::commit()
		*/
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
			$fields['filename'] = $this->get('filename');

			$this->_engine->Database->query("DELETE FROM `tbl_fields_".$this->handle()."` WHERE `field_id` = '$id' LIMIT 1");		
			return $this->_engine->Database->insert($fields, 'tbl_fields_' . $this->handle());
		}
	}