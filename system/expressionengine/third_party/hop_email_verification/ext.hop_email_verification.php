<?php

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(-1);

class Hop_email_verification_ext {

    var $name       	= 'Hop Email Verification';
    var $version        = '1.0';
    var $description    = 'Verifies new email address before update';
    var $settings_exist = 'n';
    var $docs_url       = 'http://www.hopstudios.com/software/';

    var $settings       = array();

    /**
     * Constructor
     *
     * @param   mixed   Settings array or empty string if none exist.
     */
    function __construct($settings = '')
    {
        $this->settings = $settings;
    }
	
	/**
	 * Activate Extension
	 *
	 * This function enters the extension into the exp_extensions table
	 *
	 * @see https://ellislab.com/codeigniter/user-guide/database/index.html for
	 * more information on the db class.
	 *
	 * @return void
	 */
	function activate_extension()
	{
		$this->settings = array(
			
		);


		$data = array(
			'class'     => __CLASS__,
			'method'    => 'check_verification',
            'hook' => 'user_edit_insert_data',
			'settings'	=> serialize($this->settings),
			'priority'  => 10,
			'version'   => $this->version,
			'enabled'   => 'y'
		);

		ee()->db->insert('extensions', $data);    
        unset($data);
        
        /*
        * Creats the extension table with member_id, verification_code, verification_email
        */
        // We prefer using APIs instead of RAW SQL when possible :)
		// This should work but you might want to verify it
		ee()->load->dbforge();
		$fields = array(
			'member_id'				=> array('type' => 'INT', 'constraint' => '10', 'unsigned' => TRUE),
			'verification_code' 	=> array('type' => 'VARCHAR', 'constraint' => '10'),
			'verification_email'	=> array('type' => 'VARCHAR', 'constraint' => '500')
		);

		ee()->dbforge->add_field($fields);
		ee()->dbforge->add_key('verification_code', TRUE);
		ee()->dbforge->add_key('member_id', FALSE);
		ee()->dbforge->add_key('verification_email', FALSE);
		ee()->dbforge->create_table('hop_email_verification');

		unset($fields);
	}
	
	/**
	 * Update Extension
	 *
	 * This function performs any necessary db updates when the extension
	 * page is visited
	 *
	 * @return  mixed   void on update / false if none
	 */
	function update_extension($current = '')
	{
		if ($current == '' OR $current == $this->version)
		{
			return FALSE;
		}

		if ($current < '1.0')
		{
			// Update to version 1.0
		}

		ee()->db->where('class', __CLASS__);
		ee()->db->update(
					'extensions',
					array('version' => $this->version)
		);
	}
	
	/**
	 * Disable Extension
	 *
	 * This method removes information from the exp_extensions table
	 *
	 * @return void
	 */
	function disable_extension()
	{
		ee()->db->where('class', __CLASS__);
		ee()->db->delete('extensions');
        
        /*
        * Drops the extension table
        */        
        ee()->load->dbforge();
        ee()->dbforge->drop_table('hop_email_verification');
	}
    
    /*
     * Check that our verification code is unique in this table
    */
    function is_unique($verification_code)
    {
        $query_code = ee()->db->select('member_id')
            ->from('hop_email_verification')
            ->where('verification_code', $verification_code)
            ->get(); 
        
        if ($query_code->num_rows() == 0)
        {
            return true;
        }
        else
        {
            return false;
        }
    }
    
    /**
     *   Meat and potatoe of the extension
     *   Creates and sends email verification code to new email address
     */
    function send_verification_code($member_id, $data)
    {
        
        if (isset($data['email']) && $data['email'] != null && trim($data['email']) != '' )
        {
            //Collect submitted email
            $verification_email = $data['email'];

            if ($member_id == "") { return; }  //member has no member id

            //Check the user exists, retreive email and screen_name for email
             $query_email = ee()->db->select('screen_name')
                ->from('members')
                ->where('member_id',$member_id)
                ->get(); 

            if ($query_email->num_rows() != 0)
            {
                foreach ($query_email->result() as $row)
                {
                    //We send to the verification email (the original email has already been authenticated and they can only trigger this within their account)
                    //Use screen name for email
                    $screen_name = $row->screen_name;

                    //Generate a random alphanumber 10 characters in length
                    // TODO ensure this is unique
                    $verification_code = random_string('alnum', 10);

                    $flag = false;
                    while ($flag == false)
                    {
                        $flag = $this->is_unique($verification_code);
                    }

                    //insert query
                    $insert_data = array( 'member_id'=> $member_id, 'verification_code' => $verification_code, 'verification_email' => $verification_email );
                    ee()->db->query(
                        ee()->db->insert_string('hop_email_verification', $insert_data)
                    );
                    unset($data);

                    //Prepare email content
                    $subject = "Change of Email - Verification Required";
                    $message = "
                    <p>Hi ".ucwords($screen_name)."</p>
                    <p>A request has been made to change your email address to this new address.</p>
                    <p>To do so you will need to enter code below in order to complete this process.
                    <p>Verification code: <b>$verification_code</b></p>
                    <p>Please enter your verification code <a href=\"".ee()->config->item('site_url')."profile\">here</a> to complete the process.</p>
                    <p>Thank you,</p>
                    <p>GGIA</p>
                    ";

                    //load email lib
                    ee()->load->library('email');

                    ee()->load->helper('text');

                    ee()->email->initialize();
                    ee()->email->wordwrap = true;
                    ee()->email->mailtype = ee()->config->item('mail_format');
                    //use webmaster from address
                    ee()->email->from(ee()->config->item('webmaster_email'), ee()->config->item('webmaster_name'));
                    ee()->email->to($verification_email);
                    ee()->email->subject($subject);
                    ee()->email->message(entities_to_ascii($message));
                    ee()->email->Send();

                    //Provide a response for ajax form - in json format
                    $json_response = json_encode(array("header"=>"Notice","error_message"=>"A verification code was emailed to the new address.<br />Please check your email.") );
                    echo $json_response;
                }
            }
            else
            {
                //Provide a response for ajax form - in json format
                $json_response = json_encode(array("header"=>"Notice","error_message"=>"We were unable to retreive an email address with your member data. Please contact admin.") );
                echo $json_response;
            }
        }
        else
        {
            //Provide a response for ajax form - in json format - email is required
            $json_response = json_encode(array("header"=>"Notice","error_message"=>"Please enter an email address to continue.") );
            echo $json_response;
        }
    }

	/**
	 * 
	 * Figure out which route to take
	 */
	function check_verification($member_id, $data)
	{    
        
        
        if (ee()->input->post('cancel') != null)
        {
            ee()->db->delete('hop_email_verification', array('member_id' => $member_id));
            
            $json_response = json_encode(array("header"=>"Cancelled","error_message"=>"We have cancelled this change.") );
            echo $json_response;
            ee()->extensions->end_script = TRUE;
        }
        else if (ee()->input->post('verification_code') == null) //!)isset($data['verification_code'])
        {
            $this->send_verification_code($member_id, $data);
            ee()->extensions->end_script = TRUE;
        }
        else 
        {
            //error_log(var_dump($data),0);
            if (!isset($data['email']))
            {
                error_log('NO EMAIL', 0);
                $query_email = ee()->db->select('verification_email')
                ->from('hop_email_verification')
                ->where('member_id',$member_id)
                ->get(); 

                if ($query_email->num_rows() != 0)
                {
                    error_log('NO RESULTS', 0);
                    foreach ($query_email->result() as $row)
                    {
                        error_log("RESULTS $row->verification_email", 0);
                        $data['email'] = $row->verification_email;
                        error_log("data-email = ".$data['email'] , 0);
                    }
                }   
            }

            $verification_email = $data['email'];
            
            //$verification_code = $data['verification_code'];
            $verification_code = ee()->input->post('verification_code');
            
            //Data validation on verification code
            if ((! preg_match('/[^a-z0-9]/i', $verification_code)) && (strlen($verification_code) != 10) )
            {
                //Provide a response for ajax form - in json format
                $json_response = json_encode(array("header"=>"Notice","error_message"=>"The code entered was invalid. ") );
                echo $json_response;
                ee()->extensions->end_script = TRUE;
                
            }
            else
            {
                //Check the code exists for said user
                $query_code = ee()->db->select('member_id')
                ->from('hop_email_verification')
                ->where('member_id',$member_id)
                ->where('verification_code', $verification_code)
                ->get(); 

                $verification_code_count = $query_code->num_rows();

                if ($verification_code_count != 0)
                {
                    //Manually update username (email = username on this site)
                    //$username_data = array( "username" => $verification_email );
                    //ee()->db->where('member_id', $member_id);
                    //ee()->db->update('members', username_data);

                    $data['email'] = $verification_email;
                    $data['username'] = $verification_email;

                    //Remove verificiation data (manipulates the user end to show a different form)
                    ee()->db->delete('hop_email_verification', array('member_id' => $member_id));
                    //SUCCESS
                    return $data;
                }
                else
                {
                    //Provide a response for ajax form - in json format
                    $json_response = json_encode(array("header"=>"Notice","error_message"=>"The code entered didn't match our records.") );
                    echo $json_response;
                    ee()->extensions->end_script = TRUE;
                }
            }            
        }
	}
}
