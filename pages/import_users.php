<?php

require_once( 'core.php' );
require_api( 'gpc_api.php' );
require_api( 'config_api.php' );
require_api( 'user_api.php' );
require_api( 'utility_api.php' ); 
require_api( 'email_api.php' );
require_api( 'email_queue_api.php' );

# Get submitted data
$f_import_file = gpc_get_string( 'import_file' );
$f_skip_first = gpc_get_bool( 'cb_skip_first_line' );     
$f_separator = gpc_get_string('edt_cell_separator');     

# Check given parameters - File
$f_import_file = gpc_get_file( 'import_file', -1 ); 
$t_file_content = array();
$t_file_content = file( $f_import_file['tmp_name'] );

# Import file content
$t_first_run = true;
foreach( $t_file_content as $t_file_row ) 	{
    
	# Check if first row skipped
	if( $t_first_run && $f_skip_first ) {
		$t_first_run = false;
		continue;
	}
	
	# Explode into elements
	$t_file_row = explode( $f_separator, $t_file_row );
    
    # trim space at beginning and end
	$t_file_row = array_map('trim', $t_file_row);
    
	# Variables
	$f_username        					= $t_file_row[0];
	$f_realname        					= $t_file_row[1];
	$f_password        					= $t_file_row[3];
	$f_password_verify 					= $t_file_row[3];
	$f_email           					= $t_file_row[2];
	$f_access_level    					= $t_file_row[4];
	$f_send_email_notification		    = $t_file_row[5];
    
	# check access level
	$f_access_level = trim($f_access_level);
	if ( is_blank( $f_access_level ) ) {
		$f_access_level = config_get( 'default_new_account_access_level' );
	}
	
	# check for empty username
	$f_username = trim( $f_username );
	if ( is_blank( $f_username ) ) {
		continue;
	} 
	
	# check if user already exists
	if( !user_is_name_valid( $f_username ) ) {
		echo "Not a valid username : ".$f_username;
		echo "<br>";
		continue;
	} 
	if ( !user_is_name_unique($f_username) ) {
		echo "User already exist :  ".$f_username;
		echo "<br>";
		continue;
	}
	
	#check if it is a valid email address
	if ( is_blank( $f_email ) || !filter_var( $f_email, FILTER_VALIDATE_EMAIL ) ) {
		echo "No valid email email address for ".$f_username;
		echo "<br>";
		continue;
	} 
	
	# check unique email address
	if (!user_is_email_unique( $f_email ) ) {
		echo "Email address already in use for ".$f_username;
		echo "<br>";
		continue;
	}        
	
	if( is_blank( $f_password )) {
	
        # adduser
        # generate signup process for username and email
        
        $t_user_id = false;

        try {
            # Signup
            $t_success = user_signup( $f_username, $f_email );

            if( $t_success ) {
                $t_user_id = user_get_id_by_name( $f_username );

                if( $t_user_id === false ) {
                    echo "User '$f_username' erstellt, aber ID nicht gefunden.<br>";
                    continue;
                }
            
                #set data for user-update command
                $t_data = array(
                    'query' => array(
                        'user_id' => $t_user_id
                    ),
                    'payload' => array(
                        'user' => array(
                            'real_name' => $f_realname,
                            'access_level' => array( 'id' => $f_access_level ),
                        ),
                        'notify_user' => 0
                    )
                );

                $t_command = new UserUpdateCommand( $t_data );
                $t_command->execute();

                echo "User created: $f_username<br>";
            } else {
                echo "Signup failed for: $f_username.<br>";
            }

        } catch( Exception $e ) {
            echo "Exception at Signup for $f_username: " . $e->getMessage() . "<br>";
            continue;
        }
    } else {
        try {
            $t_admin_name = user_get_name( auth_get_current_user_id() );
            $t_password_hashed = auth_process_plain_password( $f_password );
            
            $t_original_reset = config_get( 'send_reset_password' );
            config_set_cache( 'send_reset_password', OFF, null );
            
            $t_user_id = user_create(
                $f_username,
                $t_password_hashed,
                $f_email,
                $f_access_level,
                false,                      // protected
                true,                       // enabled
                $f_realname
            );
            
            config_set_cache( 'send_reset_password', $t_original_reset, null );
            
            if( $t_user_id === false ) {
                echo "Creation failed for: $f_username<br>";
                continue;
            }

            echo "User created:: $f_username (ID: $t_user_id)<br>";
            
            if( !is_blank( $f_email ) && config_get( 'enable_email_notification' ) == ON && $f_send_email_notification) {

                $t_subject = "[DRK Verden MDE Bugtracker] Your account has been created";

                $t_body = "Hello " . $f_realname . ",\n\n"
                        . "an administrator created an account for you.\n\n"
                        . "Username: " . $f_username . "\n"
                        . "E-Mail: " . $f_email . "\n\n";

                $t_body .= "The administrator has set a password for you,\n"
                        . "please contact him to get it, if you don't have it already.\n\n";

                $t_body .= "Login-URL: " . config_get( 'path' ) . "login_page.php\n\n"
                        . "Please contact the administrator if you have any questions.\n\n"
                        . "Kind regards,\n"
                        . "The $g_window_title Team";

                // Absender (kann in config_inc.php über $g_from_email usw. gesteuert werden)
                $t_from = config_get( 'from_email' );
                
                $t_email_data = new EmailData();
                $t_email_data->email = $f_email;
                $t_email_data->subject = $t_subject;
                $t_email_data->body = $t_body;
                $t_email_data->metadata = array();
                $t_email_data->metadata['charset'] = 'utf-8';
                    

                $_t_mail_succ = email_send( $t_email_data );
                if ( $_t_mail_succ ) {
                    echo "Info-Email has been sent to user<br>";
                } else {
                    echo "Failure sending mail for $f_username <br>";
                    error_log("Failure sending mail for $f_username ($f_email)");
                }
            }
        } catch( Exception $e ) {
            echo "Exception at Signup for $f_username: " . $e->getMessage() . "<br>";
            continue;
        }
    }
}
echo "Please hit the back button of your browse to return to mantis";
