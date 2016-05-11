<?php

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(-1);

class Hop_email_verification_ext {

    var $name       	= 'Hop Email Verification';
    var $version        = '1.0';
    var $description    = 'Verifies new email address before update';
    var $settings_exist = 'n';
    var $docs_url       = ''; // 'https://ellislab.com/expressionengine/user-guide/';

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
        
        /*
        * Creats the extension table with member_id, verification_code, verification_email
        */
        ee()->db->query("
            CREATE TABLE IF NOT EXISTS `exp_hop_email_verification` (
                `member_id` INT(11) NOT NULL,
                `verification_code` VARCHAR(10) NULL,
                `verification_email` VARCHAR(500) NULL
            );"
        );
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
        ee()->db->query("
            DROP TABLE IF EXISTS `exp_hop_email_verification`;"
        );
	}
    
    /**
     *   Meat and potatoe of the extension
     *   Creates and sends email verification code to new email address
     */
    function send_verification_code($member_id)
    {
        //Collect submitted email
        $verification_email = ee()->input->post('email');
        //Collect original email (hidden input)
        $original_email = ee()->input->post('orig_email');
        
		if ($member_id == "") { return; }  //member has no member id
		
        //Check the user exists, retreive email and screen_name for email
		 $query_email = ee()->db->select('email, screen_name')
			->from('members')
			->where('member_id',$member_id)
			->get(); 
        
		if ($query_email->num_rows() != 0)
		{
            foreach ($query_email->result() as $row)
            {
                //We send to the verification email (the original email has already been authenticated and they can only trigger this within their account)
                $email = $verification_email;
                //Use screen name for email
                $screen_name = $row->screen_name;
                
                //Generate a random alphanumber 10 characters in length
                $verification_code = random_string('alnum', 10);

                //Check if it exists to determine an insert or a update query
                $exists_query = ee()->db->select('member_id')
                    ->from('hop_email_verification')
                    ->where('member_id',$member_id)
                    ->get(); 
                
                if ($exists_query->num_rows() == 0)
		        {
                    //insert query
                    $data = array( 'member_id'=> $member_id, 'verification_code' => $verification_code, 'verification_email' => $verification_email );
                    ee()->db->query(
                        ee()->db->insert_string('hop_email_verification', $data)
                    );
                }
                else
                {
                    //update query
                    $data = array( 'verification_code' => $verification_code, 'verification_email' => $verification_email );
                    ee()->db->query(
                        ee()->db->update_string(
                            'hop_email_verification',
                            $data,
                            'member_id = "' . $member_id . '"'
                        )
                    );
                }
                
                //Prepare email content
                $subject = "Change of Email - Verification Required";
                $message = "
                <p>Hi ".ucwords($screen_name)."</p>
                <p>A request has been made to change your email address to this new address.</p>
                <p>To do so you will need to enter code below in your profile in order to complete this process.
                <p>Please find the verification following code: <b>$verification_code</b></p>
                <p>TTFN,</p>
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
                ee()->email->to($email);
                ee()->email->subject($subject);
                ee()->email->message(entities_to_ascii($message));
                ee()->email->Send();
                
                /* MANUALLY RESET USERNAME & PASSWORD - NO LONGER NEEDED - CONFIRM */
                /* $data = array( 'email' => $original_email, 'username' => $original_email );
                ee()->db->query(
                    ee()->db->update_string(
                        'members',
                        $data,
                        'member_id = "' . $member_id . '"'
                    )
                ); */
                
                //Provide a response for ajax form - in json format
                $json_response = json_encode(array("header"=>"Notice","error_message"=>"A verification code was emailed to the new address. Please check your emails and wait while we refresh the page...") );
                echo $json_response;
                //Not sure this is needed anymore?? It worked like this so I don't want to break it
                ee()->extensions->end_script = FALSE;
                //Seems to be the only way to kill the script before it submits the email address
                exit;
            }
		}
		else
		{
			error_log("No results",0);
		}
    }

	/**
	 * 
	 * Figure out which route to take
	 */
	function check_verification($member_id)
	{    
        //Determine whether we are creating a verification code or checking one is correct - if null - create
        if (ee()->input->post('email_auth') == null || ee()->input->post('email_auth') == '')
        {
            $this->send_verification_code($member_id);
        }
        else 
        {
            //Collect form data
            $verification_email = ee()->input->post('email');
            $original_email = ee()->input->post('orig_email');
            $verification_code = ee()->input->post('email_auth');
            
            //Data validation on verification code
            if ((! preg_match('/[^a-z0-9]/i', $verification_code)) && (strlen($verification_code) != 10) )
            {                
                /* MANUALLY RESET USERNAME & EMAIL - NO LONGER NEEDED - CONFIRM */
                /* $data = array( 'email' => $original_email );
                ee()->db->query(
                    ee()->db->update_string(
                        'members',
                        $data,
                        'member_id = "' . $member_id . '"'
                    )
                );
                $data = array( 'username' => $original_email );
                ee()->db->query(
                    ee()->db->update_string(
                        'members',
                        $data,
                        'member_id = "' . $member_id . '"'
                    )
                );*/
                
                //Provide a response for ajax form - in json format
                $json_response = json_encode(array("header"=>"Notice","error_message"=>"The code entered was invalid") );
                echo $json_response;
                //Not sure this is needed anymore?? It worked like this so I don't want to break it
                ee()->extensions->end_script = FALSE;
                //Seems to be the only way to kill the script before it submits the email address
                exit;
                
            }
            
            //Check the code exists for said user
            $query_code = ee()->db->select('member_id')
			->from('hop_email_verification')
			->where('member_id',$member_id)
            ->where('verification_code', $verification_code)
			->get(); 
            
            if ($query_code->num_rows() != 0)
            {                
                //The system uses emails as usernames so we need to manually fix this too
                /* USERNAME = EMAIL, MANUALLY SET USERNAME */
                $data = array( 'username' => $verification_email );
                ee()->db->query(
                    ee()->db->update_string(
                        'members',
                        $data,
                        'member_id = "' . $member_id . '"'
                    )
                );
                
                //Remove verificiation data (manipulates the user end to show a different form)
                ee()->db->delete('hop_email_verification', array('member_id' => $member_id));
                
                //Don't need this anymore I don't think
                //ee()->extensions->end_script = TRUE;
                //SUCCESS
                return true;
            }
            else
            {
                /* MANUALLY RESET USERNAME & PASSWORD - NO LONGER NEEDED - CONFIRM */
                /* $data = array( 'email' => $original_email );
                ee()->db->query(
                    ee()->db->update_string(
                        'members',
                        $data,
                        'member_id = "' . $member_id . '"'
                    )
                );
                $data = array( 'username' => $original_email );
                ee()->db->query(
                    ee()->db->update_string(
                        'members',
                        $data,
                        'member_id = "' . $member_id . '"'
                    )
                ); */
                
                //Provide a response for ajax form - in json format
                $json_response = json_encode(array("header"=>"Notice","error_message"=>"The code entered was invalid") );
                echo $json_response;
                //Not sure this is needed anymore?? It worked like this so I don't want to break it
                ee()->extensions->end_script = FALSE;
                //Seems to be the only way to kill the script before it submits the email address
                exit;
            }
        }
	}
}