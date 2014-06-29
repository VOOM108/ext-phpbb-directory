<?php
/**
 *
 * @package phpBB Directory
 * @copyright (c) 2014 ErnadoO
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace ernadoo\phpbbdirectory\core;

class link
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var string */
	protected $container;

	/** @var \phpbb\ext\ernadoo\phpbbdirectory\core\helper */
	protected $dir_path_helper;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/**
	 * Constructor
	 *
	 * @param \phpbb\db\driver\driver_interface $db
	 * @param \phpbb\config\config $config
	 * @param \phpbb\template\template $template
	 * @param \phpbb\user $user
	 * @param \phpbb\controller\helper $helper
	 * @param \phpbb\request\request $request
	 * @param \phpbb\auth\auth $auth
	 * @param string         $root_path   phpBB root path
	 * @param string         $php_ext   phpEx
	 */
	function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\config\config $config, \phpbb\template\template $template, \phpbb\user $user, \phpbb\controller\helper $helper, \phpbb\request\request $request, \phpbb\auth\auth $auth, $container, $dir_path_helper, $root_path, $php_ext)
	{
		$this->db			= $db;
		$this->config		= $config;
		$this->template		= $template;
		$this->user			= $user;
		$this->helper		= $helper;
		$this->request		= $request;
		$this->auth			= $auth;
		$this->container 	= $container;
		$this->dir_helper	= $dir_path_helper;
		$this->root_path	= $root_path;
		$this->php_ext		= $php_ext;
	}

	/**
	* Add a link into db
	*
	* @param array $data contains all datas to insert in db
	* @param bool $need_approval
	*/
	function add($data, $need_approval)
	{
		$this->db->sql_transaction('begin');

		$sql = 'INSERT INTO ' . DIR_LINK_TABLE . ' ' . $this->db->sql_build_array('INSERT', $data);
		$this->db->sql_query($sql);
		$notification_data['link_id'] = $this->db->sql_nextid();

		if (!$need_approval || $this->auth->acl_get('a_') || $this->auth->acl_get('m_'))
		{
			$sql = 'UPDATE ' . DIR_CAT_TABLE . '
				SET cat_links = cat_links + 1
				WHERE cat_id = ' . (int)$data['link_cat'];
			$this->db->sql_query($sql);

			$notification_type = 'directory_website';
		}
		elseif ($this->config['dir_mail'])
		{
			$notification_type = 'directory_website_in_queue';
		}

		$this->db->sql_transaction('commit');

		if(isset($notification_type))
		{
			$notification_data = array_merge($notification_data,
				array(
					'user_from'			=> (int)$data['link_user_id'],
					'link_name'			=> strip_tags($data['link_name']),
					'link_url'			=> strip_tags($data['link_url']),
					'link_description'	=> strip_tags($data['link_description']),
					'cat_id'			=> (int)$data['link_cat'],
					'cat_name'			=> strip_tags(\ernadoo\phpbbdirectory\core\categorie::getname((int)$data['link_cat'])),
				)
			);

			$phpbb_notifications = $this->container->get('notification_manager');
			$phpbb_notifications->add_notifications($notification_type, $notification_data);
		}
	}

	/**
	* Edit a link of the db
	*
	* @param array $data contains all datas to edit in db
	* @param int $u is link's id, for WHERE clause
	* @param bool $need_approval
	*/
	function edit($data, $link_id, $need_approval)
	{
		$phpbb_notifications = $this->container->get('notification_manager');
		$notification_data = array(
			'link_id'			=> (int)$link_id,
			'user_from'			=> (int)$data['link_user_id'],
			'link_name'			=> strip_tags($data['link_name']),
			'link_description'	=> strip_tags($data['link_description']),
			'cat_id'			=> (int)$data['link_cat'],
			'cat_name'			=> strip_tags(\ernadoo\phpbbdirectory\core\categorie::getname((int)$data['link_cat'])),
		);

		$old_cat = array_pop($data);

		if ($old_cat != $data['link_cat'] || $need_approval)
		{
			$phpbb_notifications->delete_notifications('directory_website', (int)$link_id);

			$this->db->sql_transaction('begin');

			$sql = 'UPDATE ' . DIR_CAT_TABLE . ' SET cat_links = cat_links - 1
				WHERE cat_id = ' . (int)$old_cat;
			$this->db->sql_query($sql);

			if(!$need_approval)
			{
				$sql = 'UPDATE ' . DIR_CAT_TABLE . ' SET cat_links = cat_links + 1
					WHERE cat_id = ' . (int)$data['link_cat'];
				$this->db->sql_query($sql);

				$notification_type = 'directory_website';
			}
			else
			{
				$data['link_active'] = false;
				$notification_type = 'directory_website_in_queue';
			}

			$this->db->sql_transaction('commit');

			$phpbb_notifications->add_notifications($notification_type, $notification_data);
		}

		$sql = 'UPDATE ' . DIR_LINK_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE link_id = ' . (int)$link_id;
		$this->db->sql_query($sql);
	}

	/**
	* Del a link of the db
	*
	* @param int $u is link's id, for WHERE clause
	*/
	function del($cat_id, $link_id, $cron = false)
	{
		$this->db->sql_transaction('begin');

		$url_array = is_array($link_id) ? $link_id : array($link_id);

		// Delete links datas
		$link_datas_ary = array(
			DIR_LINK_TABLE		=> 'link_id',
			DIR_COMMENT_TABLE	=> 'comment_link_id',
			DIR_VOTE_TABLE		=> 'vote_link_id',
		);

		$sql = 'SELECT link_banner FROM ' . DIR_LINK_TABLE . ' WHERE '. $this->db->sql_in_set('link_id', $url_array);
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			if($row['link_banner'] && !preg_match('/^(http:\/\/|https:\/\/|ftp:\/\/|ftps:\/\/|www\.).+/si', $row['link_banner']))
			{
				$banner_img = $this->dir_helper->get_banner_path(basename($row['link_banner']));

				if (file_exists($banner_img))
				{
					@unlink($banner_img);
				}
			}
		}

		foreach ($link_datas_ary as $table => $field)
		{
			$this->db->sql_query("DELETE FROM $table WHERE ".$this->db->sql_in_set($field, $url_array));
		}

		$sql = 'UPDATE ' . DIR_CAT_TABLE . '
			SET cat_links = cat_links - '.sizeof($url_array).'
		WHERE cat_id = ' . (int)$cat_id;
		$this->db->sql_query($sql);

		$this->db->sql_transaction('commit');

		$phpbb_notifications = $this->container->get('notification_manager');

		foreach($url_array as $link_id)
		{
			$phpbb_notifications->delete_notifications(array(
				'directory_website',
				'directory_website_in_queue'
			), $link_id);
		}

		if ($this->request->is_ajax())
		{
			$sql = 'SELECT cat_links FROM ' . DIR_CAT_TABLE . ' WHERE cat_id = ' . (int)$cat_id;
			$result = $this->db->sql_query($sql);
			$data = $this->db->sql_fetchrow($result);

			$json_response = new \phpbb\json_response;
			$json_response->send(array(
				'success' => true,

				'MESSAGE_TITLE'	=> $this->user->lang['INFORMATION'],
				'MESSAGE_TEXT'	=> $this->user->lang['DIR_DELETE_OK'],
				'LINK_ID'		=> $link_id,
				'TOTAL_LINKS'	=> $this->user->lang('DIR_NB_LINKS', (int)$data['cat_links']),
			));
		}
	}

	/**
	* Increments link view counter
	*
	* @param int $u is link's id, for WHERE clause
	*/
	function view($link_id)
	{
		$sql = 'SELECT link_id, link_url FROM ' . DIR_LINK_TABLE . '
					WHERE link_id = ' . (int)$link_id;
		$result = $this->db->sql_query($sql);
		$data = $this->db->sql_fetchrow($result);

		if (empty($data['link_id']))
		{
			return $this->helper->error($this->user->lang['DIR_ERROR_NO_LINKS'], E_USER_ERROR);
		}

		$sql = 'UPDATE ' . DIR_LINK_TABLE . '
			SET link_view = link_view + 1
			WHERE link_id = ' . (int)$link_id;
		$this->db->sql_query($sql);

		redirect($data['link_url'], false, true);
		return;
	}

	/**
	* Verify that an URL exist before add into db
	*
	* @param string $url
	*
	* @return true if ok, else false.
	*/
	function checkurl($url)
	{
		$details = parse_url($url);

		if (!isset($details['port']))
		{
			$details['port'] = 80;
		}
		if (!isset($details['path']))
		{
			$details['path'] = "/";
		}

		if ($sock = @fsockopen($details['host'], $details['port'], $errno, $errstr, 1))
		{
			$requete = "GET ".$details['path']." HTTP/1.1\r\n";
			$requete .= "Host: ".$details['host']."\r\n\r\n";

			// Send a HTTP GET header
			fputs($sock, $requete);
			// answer from server
			$str = fgets($sock, 1024);
			preg_match("'HTTP/1\.. (.*) (.*)'U", $str, $parts);
			fclose($sock);

			if ($parts[1] == '404')
			{
				return false;
			}
			else
			{
				return true;
			}
		}
		return false;
	}

	/**
	* Delete the final '/', if no path
	*
	* @param string $url to clean
	*
	* @return the correct string.
	*/
	function clean_url($url)
	{
		$details = parse_url($url);

		if(isset($details['path']) && $details['path'] == '/' && !isset($details['query']))
		{
			return substr($url, 0, -1);
		}
		return $url;
	}

	/**
	 * Display a flag
	 *
	 * @param array $data link's data from db
	 *
	 * @return flag image.
	 */
	function display_flag($data)
	{
		global $phpbb_extension_manager;

		$ext_path = $phpbb_extension_manager->get_extension_path('ernadoo/phpbbdirectory', false);
		$flag_path = $ext_path.'images/flags/';

		$extra = '';

		if(!empty($data['link_flag']))
		{
			if (file_exists($flag_path . $data['link_flag']))
			{
				$iso_code = substr($data['link_flag'], 0, strpos($data['link_flag'], '.'));
				$country = (isset($this->user->help[strtoupper($iso_code)])) ? $this->user->help[strtoupper($iso_code)] : '';
				$extra = 'alt = "'.$country.'" title = "'.$country.'"';

				return '<img src="' . $this->dir_helper->get_img_path('flags', $data['link_flag']) . '" '.$extra.' />&nbsp;';
			}
		}

		return '<img src="' . $this->dir_helper->get_img_path('flags', 'no_flag.png') . '" />&nbsp;';

	}

	/**
	* Calculate the link's note
	*
	* @param int $total_note is sum of all link's notes
	* @param int $nb_vote is nb of votes
	*
	* @return the calculated note.
	*/
	function display_note($total_note, $nb_vote, $votes_status)
	{
		if(!$votes_status)
		{
			return;
		}

		$note = ($nb_vote < 1) ? '' : $total_note / $nb_vote;
		$note = (strlen($note) > 2) ? number_format($note, 1) : $note;
		$note = ($nb_vote) ? '<b>' . $this->user->lang('DIR_FROM_TEN', $note) . '</b>' : $this->user->lang['DIR_NO_NOTE'];

		return $note;
	}

	/**
	* Display the vote form for auth users
	*
	* @param array $data link's data from db
	*
	* @return the html code.
	*/
	function display_vote($data, $votes_status)
	{
		if(!$votes_status)
		{
			return;
		}

		if ($this->user->data['is_registered'])
		{
			if ($this->auth->acl_get('u_vote_dir'))
			{
				if (empty($data['vote_user_id']))
				{
					$list = '<select name="vote">';
					for ( $i = 0; $i <= 10; $i++ )
					{
						$list .= '<option value="' . $i . '"' . (($i == 5) ? ' selected="selected"' : '') . '>' . $i . '</option>';
					}
					$list .= '</select>';

					return '<br /><span id="form_vote"><form action="' . $this->helper->route('phpbbdirectory_vote_controller', array('cat_id' => (int)$data['link_cat'], 'link_id' => (int)$data['link_id'])) . '" method="post" data-ajax="phpbbdirectory.add_vote" data-refresh="true"><div>' . $list . '&nbsp;<input type="submit" name="submit_vote" value="' . $this->user->lang['DIR_VOTE'] . '" class="mainoption" /></div></form></span>';
				}
			}
		}
		return '<br />';
	}

	/**
	* Display link's thumb if thumb service enabled.
	* if thumb don't exists in db or if a new service was choosen in acp
	* thumb is research
	*
	* @param array $data link's data from db
	*
	* @return thumb or nothing.
	*/
	function display_thumb($data)
	{
		if($this->config['dir_activ_thumb'])
		{
			if (!$data['link_thumb'] || ($this->config['dir_thumb_service_reverse'] && (!strstr($data['link_thumb'], 'ascreen.jpg') && (!strstr($data['link_thumb'], $this->config['dir_thumb_service'])))))
			{
				$thumb = $this->thumb_process($data['link_url']);

				$sql = 'UPDATE ' . DIR_LINK_TABLE . ' SET link_thumb = "' . $this->db->sql_escape($thumb) . '"
					WHERE link_id = ' . (int)$data['link_id'];
				$this->db->sql_query($sql);

				return $thumb;
			}
			return $data['link_thumb'];
		}
	}

	/**
	* Display and calculate PageRank if needed
	*
	* @param array $data link's data from db
	*
	* @return pr image, false or 'n/a'.
	*/
	function display_pagerank($data)
	{
		if($this->config['dir_activ_pagerank'])
		{
			if ($data['link_pagerank'] == '')
			{
				$pagerank = $this->pagerank_process($data['link_url']);

				$sql = 'UPDATE ' . DIR_LINK_TABLE . ' SET link_pagerank = ' . (int)$pagerank . '
					WHERE link_id = ' . (int)$data['link_id'];
				$this->db->sql_query($sql);
			}
			else
			{
				$pagerank = (int)$data['link_pagerank'];
			}

			$prpos=40*$pagerank/10;
			$prneg=40-$prpos;
			$html='<img src="http://www.google.com/images/pos.gif" width="'.$prpos.'" height="4" alt="'.$pagerank.'" /><img src="http://www.google.com/images/neg.gif" width="'.$prneg.'" height="4" alt="'.$pagerank.'" /> ';

			$pagerank = $pagerank == '-1' ? $this->user->lang['DIR_PAGERANK_NOT_AVAILABLE'] : $this->user->lang('DIR_FROM_TEN', $pagerank);
			return $html.$pagerank;
		}
		return false;
	}

	/**
	* Display and resize a banner
	*
	* @param array $data link's data from db
	* @param bool $have_banner
	*
	* @return banner image.
	*/
	function display_bann($data)
	{
		$s_banner = $path = '';

		if (!empty($data['link_banner']))
		{
			if (!preg_match('/^(http:\/\/|https:\/\/|ftp:\/\/|ftps:\/\/|www\.).+/si', $data['link_banner']))
			{
				$img_src = $this->helper->route('phpbbdirectory_banner_controller', array('banner_img' => $data['link_banner']));
				$physical_path = $this->dir_helper->get_banner_path($data['link_banner']);
			}
			else
			{
				$img_src = $physical_path = $data['link_banner'];
			}

			list($width, $height, $type, $attr) = @getimagesize($physical_path);
			if (($width > $this->config['dir_banner_width'] || $height > $this->config['dir_banner_height']) && $this->config['dir_banner_width'] > 0 && $this->config['dir_banner_height'] > 0)
			{
				$coef_w = $width / $this->config['dir_banner_width'];
				$coef_h = $height / $this->config['dir_banner_height'];
				$coef_max = max($coef_w, $coef_h);
				$width /= $coef_max;
				$height /= $coef_max;
			}

			$s_banner = '<img src="' . $img_src . '" width="' . $width . '" height="' . $height . '" alt="'.$data['link_name'].'" title="'.$data['link_name'].'" />';
		}

		return $s_banner;
	}

	/**
	* Display number of comments and link for posting
	*
	* @param int $u is link_id from db
	* @param int $nb_comments si number of comments for this link
	*
	* @return html code (counter + link).
	*/
	function display_comment($link_id, $nb_comment, $comments_status)
	{
		if(!$comments_status)
		{
			return;
		}

		$comment_url = $this->helper->route('phpbbdirectory_comment_view_controller', array('link_id' => (int)$link_id));
		$l_nb_comment = $this->user->lang('DIR_NB_COMMS', (int)$nb_comment);
		$s_comment = '&nbsp;&nbsp;&nbsp;<a href="' . $comment_url . '" onclick="window.open(\'' . $comment_url . '\', \'phpBB_dir_comment\', \'height=600, resizable=yes, scrollbars=yes, width=905\');return false;" class="gen"><b>' . $l_nb_comment . '</b></a>';

		return $s_comment;
	}

	/**
	* Add a vote in db, for a specifi link
	*
	* @param int $u is link_id from db
	*/
	function add_vote($cat_id, $link_id)
	{
		if (!$this->user->data['is_registered'])
		{
			return $this->helper->error($this->user->lang['DIR_ERROR_VOTE_LOGGED'], 401);
		}

		$data = array(
			'vote_link_id' 		=> (int)$link_id,
			'vote_user_id' 		=> (int)$this->user->data['user_id'],
		);

		// We check if user had already vot for this website.
		$sql = 'SELECT vote_link_id FROM ' . DIR_VOTE_TABLE . ' WHERE ' . $this->db->sql_build_array('SELECT', $data);
		$result = $this->db->sql_query($sql);
		$data = $this->db->sql_fetchrow($result);

		if (!empty($data['vote_link_id']))
		{
			return $this->helper->error($this->user->lang['DIR_ERROR_VOTE'], 403);
		}

		$data = array(
			'vote_link_id' 		=> (int)$link_id,
			'vote_user_id' 		=> $this->user->data['user_id'],
			'vote_note'			=> request_var('vote', 0),
		);

		$this->db->sql_transaction('begin');

		$sql = 'INSERT INTO ' . DIR_VOTE_TABLE . ' ' . $this->db->sql_build_array('INSERT', $data);
		$this->db->sql_query($sql);

		$sql = 'UPDATE ' . DIR_LINK_TABLE . ' SET link_vote = link_vote + 1,
			link_note = link_note + ' . (int)$data['vote_note'] . '
		WHERE link_id = ' . (int)$link_id;
		$this->db->sql_query($sql);

		$this->db->sql_transaction('commit');

		if ($this->request->is_ajax())
		{
			$sql= 'SELECT link_vote, link_note FROM ' . DIR_LINK_TABLE . ' WHERE link_id = ' . (int)$link_id;
			$result = $this->db->sql_query($sql);
			$data = $this->db->sql_fetchrow($result);

			$note = $this->display_note($data['link_note'], $data['link_vote'], true);

			$json_response = new \phpbb\json_response;
			$json_response->send(array(
				'success' => true,

				'MESSAGE_TITLE'	=> $this->user->lang['INFORMATION'],
				'MESSAGE_TEXT'	=> $this->user->lang['DIR_VOTE_OK'],
				'NOTE'			=> $note,
				'NB_VOTE'		=> $this->user->lang('DIR_NB_VOTES', (int)$data['link_vote']),
				'LINK_ID'		=> $link_id
			));
		}
	}

	/**
	* Search an appropriate thumb for url
	*
	* @param string $url is link's url
	*
	* @return the thumb url
	*/
	function thumb_process($url)
	{
		if(!$this->config['dir_activ_thumb'])
		{
			return $this->root_path.'images/directory/nothumb.gif';
		}

		$details = parse_url($url);

		$root_url		= $details['scheme'].'://'.$details['host'];
		$absolute_url	= isset($details['path']) ? $root_url.$details['path'] : $root_url;

		if($this->config['dir_activ_thumb_remote'])
		{
			if ($this->ascreen_exist($details['scheme'], $details['host']))
			{
				return $root_url.'/ascreen.jpg';
			}
		}
		return $this->config['dir_thumb_service'].$absolute_url;
	}

	/**
	 * Check if ascreen thumb exists
	 */
	function ascreen_exist($protocol, $host)
	{
		if ($thumb_info = @getimagesize($protocol.'://'.$host.'/ascreen.jpg'))
		{
			// Obviously this is an image, we did some additional tests
			if ($thumb_info[0] == '120' && $thumb_info[1] == '90' && $thumb_info['mime'] == 'image/jpeg')
			{
				return true;
			}
		}
		return false;
	}

	/**
	* primary work on banner, can edit, copy or check a banner
	*
	* @param string $banner is banner's remote url
	*/
	function banner_process(&$banner, &$error)
	{
		$old_banner = request_var('old_banner', '');

		$destination = $this->dir_helper->get_banner_path();

		// Can we upload?
		$can_upload = ($this->config['dir_storage_banner'] && file_exists($this->root_path . $destination) && phpbb_is_writable($this->root_path . $destination) && (@ini_get('file_uploads') || strtolower(@ini_get('file_uploads')) == 'on')) ? true : false;

		if ($banner && $can_upload)
		{
			$file = $this->banner_upload($banner, $error);
		}
		else if ($banner)
		{
			$file = $this->banner_remote($banner, $error);
		}
		else if (isset($_POST['delete_banner']) && $old_banner)
		{
			$this->banner_delete($old_banner);
			$banner = '';
			return;
		}

		if (!sizeof($error))
		{
			if ($banner && $old_banner && !preg_match('/^(http:\/\/|https:\/\/|ftp:\/\/|ftps:\/\/|www\.).+/si', $old_banner))
			{
				$this->banner_delete($old_banner);
			}

			$banner = isset($file) ? $file : '';
		}
		elseif(isset($file))
		{
			$this->banner_delete($file);
		}
	}

	/**
	* Copy a remonte banner to server.
	* called by banner_process()
	*
	* @param string $banner is banner's remote url
	*
	* @return file's name of the local banner
	*/
	function banner_upload($banner, &$error)
	{
		// Init upload class
		if(!class_exists('fileupload'))
		{
			include($this->root_path . 'includes/functions_upload.' . $this->php_ext);
		}
		$upload = new \fileupload('DIR_BANNER_', array('jpg', 'jpeg', 'gif', 'png'), $this->config['dir_banner_filesize']);

		$file = $upload->remote_upload($banner);

		$prefix = unique_id() . '_';
		$file->clean_filename('real', $prefix);

		$destination = $this->dir_helper->get_banner_path();

		// Move file and overwrite any existing image
		$file->move_file($destination, true);

		if (sizeof($file->error))
		{
			$file->remove();
			$error = array_merge($error, $file->error);
		}
		@chmod($file->destination_file, 0644);

		return $prefix .strtolower($file->uploadname);
	}

	/**
	* Check than remote banner exists
	* called by banner_process()
	*
	* @param string $banner is banner's remote url
	*
	* @return false if error, true for ok
	*/
	function banner_remote($banner, &$error)
	{
		if (!preg_match('#^(http|https|ftp)://#i', $banner))
		{
			$banner = 'http://' . $banner;
		}
		if (!preg_match('#^(http|https|ftp)://(?:(.*?\.)*?[a-z0-9\-]+?\.[a-z]{2,4}|(?:\d{1,3}\.){3,5}\d{1,3}):?([0-9]*?).*?\.(gif|jpg|jpeg|png)$#i', $banner))
		{
			$error[] = $this->user->lang['DIR_BANNER_URL_INVALID'];
			return false;
		}

		// Make sure getimagesize works...
		if (($image_data = @getimagesize($banner)) === false)
		{
			$error[] = $this->user->lang['DIR_BANNER_UNABLE_GET_IMAGE_SIZE'];
			return false;
		}

		if (!empty($image_data) && ($image_data[0] < 2 || $image_data[1] < 2))
		{
			$error[] = $this->user->lang['DIR_BANNER_UNABLE_GET_IMAGE_SIZE'];
			return false;
		}

		$width = $image_data[0];
		$height = $image_data[1];

		// Check image type
		if(!class_exists('fileupload'))
		{
			include($this->root_path . 'includes/functions_upload.' . $this->php_ext);
		}

		$types		= \fileupload::image_types();
		$extension	= strtolower(\filespec::get_extension($banner));

		// Check if this is actually an image
		if ($file_stream = @fopen($banner, 'r'))
		{
			// Timeout after 1 second
			stream_set_timeout($file_stream, 1);
			$meta = stream_get_meta_data($file_stream);
			foreach ($meta['wrapper_data'] as $header)
			{
				$header = preg_split('/ /', $header, 2);
				if (strtr(strtolower(trim($header[0], ':')), '_', '-') === 'content-type')
				{
					if (strpos($header[1], 'image/') !== 0)
					{
						$error[] = 'DIR_BANNER_URL_INVALID';
						fclose($file_stream);
						return false;
					}
					else
					{
						fclose($file_stream);
						break;
					}
				}
			}
		}
		else
		{
			$error[] = 'DIR_BANNER_URL_INVALID';
			return false;
		}

		if (!empty($image_data) && (!isset($types[$image_data[2]]) || !in_array($extension, $types[$image_data[2]])))
		{
			if (!isset($types[$image_data[2]]))
			{
				$error[] = $this->user->lang['UNABLE_GET_IMAGE_SIZE'];
			}
			else
			{
				$error[] = $this->user->lang('DIR_BANNER_IMAGE_FILETYPE_MISMATCH', $types[$image_data[2]][0], $extension);
			}
			return false;
		}

		if ($this->config['dir_banner_width'] || $this->config['dir_banner_height'])
		{
			if ($width > $this->config['dir_banner_width'] || $height > $this->config['dir_banner_height'])
			{
				$error[] = $this->user->lang('DIR_BANNER_WRONG_SIZE', $this->config['dir_banner_width'], $this->config['dir_banner_height'], $width, $height);
				return false;
			}
		}

		return $banner;
	}

	/**
	* Delete a banner from server
	*
	* @param string $destination path to banner directory
	* @param string $file is file's name
	*
	* @return true if delete success, else false
	*/
	function banner_delete($file)
	{
		if (file_exists($this->dir_helper->get_banner_path($file)))
		{
			@unlink($this->dir_helper->get_banner_path($file));
			return true;
		}

		return false;
	}

	/**
	* PageRank Lookup (Based on Google Toolbar for Mozilla Firefox)
	*
	* @copyright 2012 HM2K <hm2k@php.net>
	* @link http://pagerank.phurix.net/
	* @author James Wade <hm2k@php.net>
	* @version $Revision: 2.1 $
	* @require PHP 4.3.0 (file_get_contents)
	* @updated 06/10/11
	*/
	function pagerank_process($q)
	{
		$googleDomains = Array(".com", ".com.tr", ".de", ".fr", ".be", ".ca", ".ro", ".ch");
		$seed = $this->user->lang['SEED'];
		$result = 0x01020345;
		$len = strlen($q);
		for ($i=0; $i<$len; $i++)
		{
			$result ^= ord($seed{$i%strlen($seed)}) ^ ord($q{$i});
			$result = (($result >> 23) & 0x1ff) | $result << 9;
		}
		if (PHP_INT_MAX != 2147483647)
		{
			$result = -(~($result & 0xFFFFFFFF) + 1);
		}
		$ch=sprintf('8%x', $result);
		$url='http://%s/tbr?client=navclient-auto&ch=%s&features=Rank&q=info:%s';
		$host = 'toolbarqueries.google'.$googleDomains[mt_rand(0,count($googleDomains)-1)];

		$url=sprintf($url,$host,$ch,$q);
		@$pr=trim(file_get_contents($url,false,$context));

		if(is_numeric(substr(strrchr($pr, ':'), 1)))
		{
			return substr(strrchr($pr, ':'), 1);
		}
		return '-1';
	}

	/**
	 * List flags
	 *
	 * @param string $flag_path is flag directory path
	 * @param $value selected flag
	 *
	 * @return html code
	 */
	function get_dir_flag_list($flag_path, $value)
	{
		$list = '';

		asort($this->user->help);

		foreach ($this->user->help as $file => $name)
		{
			$img_file = strtolower($file).'.png';
			if (file_exists($flag_path.$img_file))
			{
				$list .= '<option value="' . $img_file . '" ' . (($img_file == $value) ? 'selected="selected"' : '') . '>' . $name . '</option>';
			}
		}

		return ($list);
	}

	function recents()
	{
		if($this->config['dir_recent_block'])
		{
			$limit_sql		= $this->config['dir_recent_rows'] * $this->config['dir_recent_columns'];
			$exclude_array	= explode(',', str_replace(' ', '', $this->config['dir_recent_exclude']));

			$sql_array = array(
				'SELECT'	=> 'l.link_id, l.link_cat, l.link_url, l.link_user_id, l.link_comment, l. link_description, l.link_vote, l.link_note, l.link_view, l.link_time, l.link_name, l.link_thumb, u.user_id, u.username, u.user_colour, c.cat_name',
				'FROM'		=> array(
						DIR_LINK_TABLE	=> 'l'),
				'LEFT_JOIN'	=> array(
						array(
							'FROM'	=> array(USERS_TABLE	=> 'u'),
							'ON'	=> 'l.link_user_id = u.user_id'
						),
						array(
							'FROM'	=> array(DIR_CAT_TABLE => 'c'),
							'ON'	=> 'l.link_cat = c.cat_id'
						)
				),
				'WHERE'		=> $this->db->sql_in_set('l.link_cat', $exclude_array, true).' AND l.link_active = 1',
				'ORDER_BY'	=> 'l.link_time DESC');

			$sql = $this->db->sql_build_query('SELECT', $sql_array);
			$result = $this->db->sql_query_limit($sql, $limit_sql, 0);
			$num = 0;
			$rowset = array();

			while ($site = $this->db->sql_fetchrow($result))
			{
				$rowset[$site['link_id']] = $site;
			}
			$this->db->sql_freeresult($result);

			if(sizeof($rowset))
			{
				$this->template->assign_block_vars('block', array(
					'S_COL_WIDTH'			=> (100 / $this->config['dir_recent_columns']) . '%',
				));

				foreach($rowset as $row)
				{
					if (($num % $this->config['dir_recent_columns']) == 0)
					{
						$this->template->assign_block_vars('block.row', array());
					}

					$this->template->assign_block_vars('block.row.col', array(
						'UC_THUMBNAIL'			=> '<a href="'.$row['link_url'].'" onclick="window.open(\''.$this->root_path.'directory.'.$this->php_ext.'?mode=view_url&amp;u='.$row['link_id'].'\'); return false;"><img src="'.$row['link_thumb'].'" title="'.$row['link_name'].'" alt="'.$row['link_name'].'" /></a>',
						'NAME'					=> $row['link_name'],
						'USER'					=> get_username_string('full', $row['link_user_id'], $row['username'], $row['user_colour']),
						'TIME'					=> ($row['link_time']) ? $this->user->format_date($row['link_time']) : '',
						'CAT'					=> $row['cat_name'],
						'COUNT'					=> $row['link_view'],
						'COMMENT'				=> $row['link_comment'],

						'U_CAT'					=> append_sid("{$this->root_path}directory.$this->php_ext", array('mode' => 'cat', 'id' => (int)$row['link_cat'])),
						'U_COMMENT'				=> append_sid("{$this->root_path}directory_comment.$this->php_ext", array('u' => (int)$row['link_id'])),

						'L_DIR_SEARCH_NB_CLIC'	=> ($row['link_view'] > 1) ? $this->user->lang['DIR_SEARCH_NB_CLICS'] : $this->user->lang['DIR_SEARCH_NB_CLIC'],
						'L_DIR_SEARCH_NB_COMM'	=> ($row['link_comment'] > 1) ? $this->user->lang['DIR_SEARCH_NB_COMMS']: $this->user->lang['DIR_SEARCH_NB_COMM'],
					));
					$num++;
				}

				while (($num % $this->config['dir_recent_columns']) != 0)
				{
					$this->template->assign_block_vars('block.row.col2', array());
					$num++;
				}
			}
		}
	}

	function validate_link_back($remote_url, $optional, $cron = false)
	{
		if(!$cron)
		{
			if (empty($remote_url) && $optional)
			{
				return false;
			}

			if (!preg_match('#^http[s]?://(.*?\.)*?[a-z0-9\-]+\.[a-z]{2,4}#i', $remote_url))
			{
				return 'DIR_ERROR_WRONG_DATA_BACK';
			}
		}

		if (false === ($handle = @fopen($remote_url, 'r')))
		{
			return 'DIR_ERROR_NOT_FOUND_BACK';
		}

		$buff = '';

		// Read by packet, faster than file_get_contents()
		while (!feof($handle))
		{
			$buff .= fgets($handle, 256);

			if(stristr($buff, $this->config['server_name']))
			{
				@fclose($handle);
				return false;
			}
		}
		@fclose($handle);

		return 'DIR_ERROR_NO_LINK_BACK';
	}

	function check($cat_id, $nb_check, $next_prune)
	{
		$del_array = $update_array = array();

		$sql_array = array(
			'SELECT'	=> 'link_id, link_back, link_guest_email, link_nb_check, link_user_id, link_name, link_url, link_description',
		'FROM'		=> array(
				DIR_LINK_TABLE),
		'WHERE'		=> "link_back <> ''
			AND link_active = 1
				AND link_cat = " . (int)$cat_id
		);
		$sql = $this->db->sql_build_query('SELECT', $sql_array);
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			if($this->validate_link_back($row['link_back'], false, true) !== false)
			{
				if(!$nb_check || ($row['link_nb_check']+1) >= $nb_check)
				{
					$del_array[] = $row['link_id'];
				}
				else
				{
					// A first table containing links ID to update
					$update_array[] = $row['link_id'];
					// A second array containing several information used when sending the reminder email
					$mail_array[$row['link_id']] = $row;
				}
			}
		}
		$this->db->sql_freeresult($result);

		if (sizeof($del_array))
		{
			$this->del($cat_id, $del_array, true);
		}
		if (sizeof($update_array))
		{
			$this->update($update_array, $mail_array, $next_prune);
		}
	}

	function auto_check($cat_data)
	{
		$sql = 'SELECT cat_name
			FROM ' . DIR_CAT_TABLE . '
			WHERE cat_id = ' . (int)$cat_data['cat_id'];
		$result = $this->db->sql_query($sql, 3600);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row)
		{
			$next_prune = time() + ($cat_data['cat_cron_freq'] * 86400);

			$this->check($cat_data['cat_id'], $cat_data['cat_cron_nb_check'], $next_prune);

			$sql = 'UPDATE ' . DIR_CAT_TABLE . "
				SET cat_cron_next = $next_prune
				WHERE cat_id = " . (int)$cat_data['cat_id'];
			$this->db->sql_query($sql);

			add_log('admin', 'LOG_DIR_AUTO_PRUNE', $row['cat_name']);
		}

		return;
	}

	function update($u_array, $m_array, $next_prune)
	{
		$sql = 'UPDATE ' . DIR_LINK_TABLE . '
			SET link_nb_check = link_nb_check + 1
				WHERE ' . $db->sql_in_set('link_id', $u_array);
		$this->db->sql_query($sql);

		$phpbb_notifications = $this->container->get('notification_manager');

		foreach($u_array as $link_id)
		{
			$data = $m_array[$link_id];
			strip_bbcode($data['link_description']);

			$notification_data = array_merge($notification_data,
				array(
					'cat_name'			=> strip_tags(\ernadoo\phpbbdirectory\core\categorie::getname((int)$data['link_cat'])),
					'link_id'			=> $data['link_id'],
					'link_name'			=> strip_tags($data['link_name']),
					'link_url'			=> $data['link_url'],
					'link_description'	=> $data['link_description'],
					'next_cron' 		=> $this->user->format_date($next_prune, 'd M Y, H:i')
				)
			);

			$phpbb_notifications->add_notifications('directory_website_error_cron', $notification_data);
		}

		$this->notify($m_array);
	}
}
