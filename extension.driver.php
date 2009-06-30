<?php
	
	class Extension_ID3Field extends Extension {

	/*-------------------------------------------------------------------------
		Extension Definition:
	-------------------------------------------------------------------------*/
		
		public function about()
		{
			return array(
				'name'			=> 'Field: ID3',
				'version'		=> '0.1.0',
				'release-date'	=> '2009-06-25',
				'author'		=> array(
					'name'			=> 'Max Wheeler',
					'website'		=> 'http://icelab.com.au/',
					'email'			=> 'max@icelab.com.au'
				),
				'description'	=> 'Allows you to read and write ID3 tags.'
			);
		}
		
		public function install()
		{
			return $this->_Parent->Database->query("CREATE TABLE `tbl_fields_id3`(
				`id` int(11) unsigned NOT NULL auto_increment,
				`field_id` int(11) unsigned NOT NULL,
				`destination` varchar(255) NOT NULL,
				`validator` varchar(50),
				`title` varchar(255),
				`artist` varchar(255),
				`album` varchar(255),
				`album_artist` varchar(255),
				`comment` varchar(255),
				`genre` varchar(255),
				`lyrics` varchar(255),
				`year` varchar(255),
				PRIMARY KEY (`id`),
				KEY `field_id` (`field_id`))"
			);
		}
		
		public function uninstall()
		{
			$this->_Parent->Database->query("DROP TABLE `tbl_fields_id3`");
			return TRUE;
		}
	}