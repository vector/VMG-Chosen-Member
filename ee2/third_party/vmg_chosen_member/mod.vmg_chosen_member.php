<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * VMG Chosen Member Module Class
 *
 * @package		VMG Chosen Member
 * @version		1.5.5
 * @author		Luke Wilkins <luke@vectormediagroup.com>
 * @copyright	Copyright (c) 2011-2013 Vector Media Group, Inc.
 */

class Vmg_chosen_member {

	public $return_data;

	private $default_search_fields = array(
		'username' => 'Username', 'screen_name' => 'Screen Name', 'email' => 'Email', 'url' => 'URL',
		'location' => 'Location', 'occupation' => 'Occupation', 'interests' => 'Interests',
		'aol_im' => 'AOL IM', 'yahoo_im' => 'Yahoo! IM', 'msn_im' => 'MSN IM', 'icq' => 'ICQ',
		'bio' => 'Bio', 'signature' => 'Signature',
	);

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->EE =& get_instance();
	}

	// ----------------------------------------------------------------

	function get_results()
	{
		$result = array();

		$field_id = $this->EE->input->get('field_id');
		$is_matrix = ($this->EE->input->get('type') == 'matrix' ? true : false);
		$is_low_var = ($this->EE->input->get('type') == 'lowvar' ? true : false);
		$query = $this->EE->db->escape_like_str(strtolower($this->EE->input->post('query')));

		if ($is_matrix)
		{
			// Check/Get field/col settings
			$this->EE->db->select('mc.col_settings AS setting_data', false);
			$this->EE->db->from('exp_matrix_cols AS mc');
			$this->EE->db->where('mc.field_id', $field_id);
			$this->EE->db->where('mc.col_type', 'vmg_chosen_member');
			$settings = $this->EE->db->get()->row_array();
		}
		elseif ($is_low_var)
		{
			// Check/Get var settings
			$this->EE->db->select('lv.variable_type, lv.variable_settings AS setting_data');
			$this->EE->db->from('exp_low_variables AS lv');
			$this->EE->db->where('lv.variable_id', $field_id);
			$this->EE->db->where('lv.variable_type', 'vmg_chosen_member');
			$settings = $this->EE->db->get()->row_array();
		}
		else
		{
			// Check/Get field/col settings
			$this->EE->db->select('cf.field_settings AS setting_data', false);
			$this->EE->db->from('exp_channel_fields AS cf');
			$this->EE->db->where('cf.field_id', $field_id);
			$this->EE->db->where('cf.field_type', 'vmg_chosen_member');
			$settings = $this->EE->db->get()->row_array();
		}

		if (!empty($settings) && $this->EE->input->is_ajax_request())
		{
			$settings = unserialize(base64_decode($settings['setting_data']));

			$search_fields = $search_fields_where = $custom_search_fields = $custom_field_map = array();
			if (empty($settings['search_fields'])) $settings['search_fields'] = array('username', 'screen_name');

			// Get member custom field list
			$this->EE->db->select("m_field_id, m_field_name, m_field_label");
			$this->EE->db->from('exp_member_fields');
			$this->EE->db->order_by('m_field_order', 'asc');
			$fields = $this->EE->db->get()->result_array();

			foreach ($fields AS $key => $value)
			{
				$custom_search_fields[$value['m_field_name']] = $value['m_field_label'];
				$custom_field_map[$value['m_field_name']] = 'm_field_id_' . $value['m_field_id'];
			}

			foreach ($settings['search_fields'] AS $field)
			{
				if (array_key_exists($field, $this->default_search_fields) || array_key_exists($field, $custom_search_fields))
				{
					if (array_key_exists($field, $custom_search_fields)) $field = $custom_field_map[$field];

					$search_fields[] = $field;
					$search_fields_where[] = "LOWER({$field}) LIKE '%" . $query . "%'";
				}
			}

			// Gather and format results
			$this->EE->db->select('m.member_id, m.username, m.screen_name, ' . implode($search_fields, ', '));
			$this->EE->db->from('exp_members AS m');
			$this->EE->db->join('exp_member_data AS md', 'md.member_id = m.member_id', 'left');
			$this->EE->db->where_in('m.group_id', $settings['allowed_groups']);
			$this->EE->db->where('(' . implode(' OR ', $search_fields_where) . ')');
			$this->EE->db->limit(50);
			$results = $this->EE->db->get()->result_array();

			foreach ($results AS $member)
			{
				$additional_text = '';

				// Add "additional" text for default fields
				foreach ($this->default_search_fields AS $field_id => $field_label)
				{
					if (isset($member[$field_id]) && empty($additional_text) && !in_array($field_id, array('username', 'screen_name')) && strpos($member[$field_id], $query) !== FALSE)
					{
						$additional_text = '&nbsp;&nbsp;&nbsp;(' . $field_label . ': ' . $this->clean_additional($member[$field_id], $query) . ')';
					}
				}

				// Add "additional" text for custom fields
				if (empty($additional_text))
				{
					foreach ($custom_search_fields AS $field_id => $field_label)
					{
						if (isset($member[$custom_field_map[$field_id]]) && empty($additional_text) && strpos($member[$custom_field_map[$field_id]], $query) !== FALSE)
						{
							$additional_text = '&nbsp;&nbsp;&nbsp;(' . $field_label . ': ' . $this->clean_additional($member[$custom_field_map[$field_id]], $query) . ')';
						}
					}
				}

				$result[] = array(
					'value' => $member['member_id'],
					'text' => $member['screen_name'] . $additional_text,
				);
			}
		}

		$this->EE->load->library('javascript');

		exit($this->EE->javascript->generate_json($result, TRUE));
	}

	// ----------------------------------------------------------------

	private function clean_additional($text, $search, $max_length = 25)
	{
		if (strlen($text) > $max_length)
		{
			$text = preg_replace('/[^[:alnum:][:punct:] ]/', '', $text);
			$find_string = strpos($text, $search);
			$text = substr($text, $find_string - $max_length, strlen($search) + ($max_length*2));

			$text = '...' . $text . '...';
		}

		return '<i>' . $text . '</i>';
	}

	// ----------------------------------------------------------------

	public function assoc_entries()
	{
		// Assist parsing of global variables as parameters
		foreach ($this->EE->TMPL->tagparams AS $key => $val)
		{
			$this->EE->TMPL->tagparams[$key] = $this->EE->TMPL->parse_globals($val);
		}

		$prefix = $this->EE->TMPL->fetch_param('prefix', 'cm_');
		$field = $this->EE->TMPL->fetch_param('field');
		$col = $this->EE->TMPL->fetch_param('col');
		$member_id = $this->EE->TMPL->fetch_param('member_id', '');
		$member_ids = explode('|', $member_id);

		// Let's guarantee that we only consider users that still exist
		$this->EE->db->select('member_id');
		$this->EE->db->from('exp_members AS m');
		$this->EE->db->where_in('m.member_id', $member_ids);
		$member_results = $this->EE->db->get()->result_array();

		$member_ids = array();
		foreach ($member_results AS $member) $member_ids[] = $member['member_id'];

		if (empty($member_ids)) return $this->EE->TMPL->no_results();

		// Check if this is a valid field
		$this->EE->db->select("cf.field_id AS field_id, mc.col_id, IF(mc.col_id IS NULL, cf.field_name, mc.col_name) AS field_name", false)
			->from('exp_channel_fields AS cf')
			->join('exp_matrix_cols AS mc', 'mc.field_id = cf.field_id', 'left')
			->where("((cf.field_type = 'vmg_chosen_member' || cf.field_type = 'matrix') AND cf.field_name = " . $this->EE->db->escape($field) . ")");

		if ( ! empty($col)) $this->EE->db->where("(cf.field_type = 'matrix' AND mc.col_type = 'vmg_chosen_member' AND mc.col_name = " . $this->EE->db->escape($col) . ")");

		$field = $this->EE->db->get()->row_array();

		$temp_results = array();

		if (!empty($field['field_id']) && is_numeric($field['field_id']) && empty($field['col_id']))
		{
			// Get channel entries
			$this->EE->db->select('cd.entry_id')
				->from('exp_channel_data AS cd');

			foreach ($member_ids AS $member_id) $this->EE->db->or_where("(field_id_" . $field['field_id'] . " REGEXP '^" . $member_id . "$|^" . $member_id . "\\\||\\\|" . $member_id . "\\\||\\\|" . $member_id . "$')");

			$temp_results = $this->EE->db->get()->result_array();
		}
		elseif (!empty($field['field_id']) && !empty($field['col_id']) && is_numeric($field['col_id']))
		{
			// Get matrix entries
			$this->EE->db->select('md.entry_id')
				->from('exp_matrix_data AS md');

			foreach ($member_ids AS $member_id) $this->EE->db->or_where("(col_id_" . $field['col_id'] . " REGEXP '^" . $member_id . "$|^" . $member_id . "\\\||\\\|" . $member_id . "\\\||\\\|" . $member_id . "$')");

			$temp_results = $this->EE->db->get()->result_array();
		}

		if (empty($temp_results)) return $this->EE->TMPL->no_results();

		foreach ($temp_results AS $result) $results[] = $result['entry_id'];

		$results = array(
			$prefix . 'entry_ids' => implode('|', array_unique($results))
		);

		if (empty($this->EE->TMPL->tagdata)) return $results[$prefix . 'entry_ids'];

		return $this->EE->TMPL->parse_variables_row($this->EE->TMPL->tagdata, $results);
	}

}

/* End of file mod.vmg_chosen_member.php */
/* Location: /system/expressionengine/third_party/vmg_chosen_member/mod.vmg_chosen_member.php */
