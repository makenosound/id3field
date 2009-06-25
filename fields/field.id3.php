<?php

	ini_set("display_errors","2");
	ERROR_REPORTING(E_ALL);
	
	if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');
	
	require_once(TOOLKIT . '/fields/field.upload.php');
	
	class FieldID3 extends FieldUpload {
		protected $_driver = null;
		public $_ignore = array();
		
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
			## Start: Pulled straight from field.upload.php
			
			$status = parent::__OK__;

			## Its not an array, so just retain the current data and return
			if(!is_array($data)){

				$status = parent::__OK__;

				# Do a simple reconstruction of the file meta information. This is a workaround for
				# bug which causes all meta information to be dropped
				return array(
					'file' => $data,
					'mimetype' => parent::__sniffMIMEType($data),
					'size' => filesize(WORKSPACE . $data),
					'meta' => serialize(parent::getMetaInfo(WORKSPACE . $data, parent::__sniffMIMEType($data)))
				);

			}

			if($simulate) return;

			if($data['error'] == UPLOAD_ERR_NO_FILE || $data['error'] != UPLOAD_ERR_OK) return;

			## Sanitize the filename
			$data['name'] = Lang::createFilename($data['name']);

			## Upload the new file
			$abs_path = DOCROOT . '/' . trim($this->get('destination'), '/');
			$rel_path = str_replace('/workspace', '', $this->get('destination'));

			if(!General::uploadFile($abs_path, $data['name'], $data['tmp_name'], $this->_engine->Configuration->get('write_mode', 'file'))){

				$message = __('There was an error while trying to upload the file <code>%1$s</code> to the target directory <code>%2$s</code>.', array($data['name'], 'workspace/'.ltrim($rel_path, '/')));
				$status = parent::__ERROR_CUSTOM__;
				return;
			}

			if($entry_id){
				$row = $this->Database->fetchRow(0, "SELECT * FROM `tbl_entries_data_".$this->get('id')."` WHERE `entry_id` = '$entry_id' LIMIT 1");
				$existing_file = $abs_path . '/' . basename($row['file']);

				General::deleteFile($existing_file);
			}

			$status = parent::__OK__;

			$file = rtrim($rel_path, '/') . '/' . trim($data['name'], '/');

			## If browser doesn't send MIME type (e.g. .flv in Safari)
			if (strlen(trim($data['type'])) == 0){
				$data['type'] = 'unknown';
			}
			## End: Pulled straight from field.upload.php
			
			
			## Figure out ID3
			$id3_data = $this->retrieve_id3_data();
			
			## Write out ID3 information			
			$this->write_id3_tags($id3_data, $file);
			
			$file_data = array(
				'file' => $file,
				'size' => $data['size'],
				'mimetype' => $data['type'],
				'meta' => serialize(parent::getMetaInfo(WORKSPACE . $file, $data['type'])),
			);
			
			$input = array_merge($file_data, $id3_data);
			return $input;			
		}
		
		/*-------------------------------------------------------------------------
			ID3 related:
		-------------------------------------------------------------------------*/
		private function retrieve_id3_data()
		{
			return array(
				'title'					=> "Hello",
				'artist' 				=> "Artist",
				'album' 				=> "Album",
				'album_artist'	=> "Album Artist",
				'comment'			=> "Donec Aenean mi placerat metus ac ornare semper. magna eget, dui enim est.",
				'genre'					=> "Podcast",
				'lyrics'				=> "Facilisis sit feugiat Mauris facilisis.",
				'year'					=> 2009,
				'duration'			=> 3591,
			);
		}
		
		private function write_id3_tags($tag_data, $file)
		{		
			$format = 'UTF-8';
			// Initialize getID3 engine
			require_once(EXTENSIONS . '/id3field/lib/getid3/getid3.php');
			$getID3 = new getID3;
			$getID3->setOption(array('encoding'=>$format));

			// Initialize getID3 tag-writing module
			require_once(EXTENSIONS . '/id3field/lib/getid3/write.php');
			$writer = new getid3_writetags;
#			$writer->filename       = '/path/to/file.mp3';			
			$writer->filename       = WORKSPACE . $file;
			$writer->tagformats     = array('id3v1', 'id3v2.3');

			# Options (optional)
			$writer->overwrite_tags = true;
			$writer->tag_encoding   = $format;
			$writer->remove_other_tags = true;

			foreach ($tag_data as &$item)
			{
				$item = array((string) $item); 
			}
			$tag_data['track'][]   = '04/16';
			print_r($tag_data);
			$writer->tag_data = $tag_data;
			
			# Need to get the duration and pass it back
			
			# Write the tags
			if ($writer->WriteTags())
			{
				# $tagwriter->warnings
				return true; # Should return duration as well
			} else {
				return $tagwriter->errors;
			}
		}
	}