<?php
/**
 * Plugin Name: MIGS gateway for Events Manager Pro-edited
 * Plugin URI: http://rkvisit.blogspot.com
 * Description: hsbc payment gate way implementation for Events Manager Pro.
 * Version: 1.0.0
 * Author: Roshan Karunarathna
 * Author URI: http://rkvisit.blogspot.com
 * 
 * @package empro
 * @category Core
 * @author Nibaya
 */
function migs_payment_gateway(){
	
class EM_Gateway_Migs extends EM_Gateway {
	//change these properties below if creating a new gateway, not advised to change this for Migs
	var $gateway = 'migs';
	var $title = 'Pay with Credit/Debit Card';
	var $status = 4;
	var $status_txt = 'Awaiting Migs Payment';
	var $button_enabled = true;
	var $payment_return = true;
	var $count_pending_spaces = false;
	var $supports_multiple_bookings = true;

	/**
	 * Sets up gateaway and adds relevant actions/filters 
	 */
	function __construct() {
		//Booking Interception
	    if( $this->is_active() && absint(get_option('em_'.$this->gateway.'_booking_timeout')) > 0 ){
	        $this->count_pending_spaces = true;
	    }
		parent::__construct();
		$this->status_txt = __('Awaiting Migs Payment','em-pro');
		if($this->is_active()) {
			add_action('em_gateway_js', array(&$this,'em_gateway_js'));
			//Gateway-Specific
			add_action('em_template_my_bookings_header',array(&$this,'say_thanks')); //say thanks on my_bookings page
			add_filter('em_bookings_table_booking_actions_4', array(&$this,'bookings_table_actions'),1,2);
			add_filter('em_my_bookings_booking_actions', array(&$this,'em_my_bookings_booking_actions'),1,2);
			//set up cron
			$timestamp = wp_next_scheduled('emp_migs_cron');
			if( absint(get_option('em_migs_booking_timeout')) > 0 && !$timestamp ){
				$result = wp_schedule_event(time(),'em_minute','emp_migs_cron');
			}elseif( !$timestamp ){
				wp_unschedule_event($timestamp, 'emp_migs_cron');
			}
		}else{
			//unschedule the cron
			wp_clear_scheduled_hook('emp_migs_cron');			
		}
	}
	
	/* 
	 * --------------------------------------------------
	 * Booking Interception - functions that modify booking object behaviour
	 * --------------------------------------------------
	 */
	
	/**
	 * Intercepts return data after a booking has been made and adds migs vars, modifies feedback message.
	 * @param array $return
	 * @param EM_Booking $EM_Booking
	 * @return array
	 */
	function booking_form_feedback( $return, $EM_Booking = false ){
		//Double check $EM_Booking is an EM_Booking object and that we have a booking awaiting payment.
		if( is_object($EM_Booking) && $this->uses_gateway($EM_Booking) ){
			if( !empty($return['result']) && $EM_Booking->get_price() > 0 && $EM_Booking->booking_status == $this->status ){
				$return['message'] = get_option('em_migs_booking_feedback');	
				$migs_url = $this->get_migs_url();	
				$migs_vars = $this->get_migs_vars($EM_Booking);	
				$md5Hash = "XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX";
				$final=array(	
					'vpc_Version' => $migs_vars['vpc_Version'],
					'vpc_Command' => $migs_vars['vpc_Command'],
					'vpc_AccessCode' => $migs_vars['vpc_AccessCode'],
					'vpc_MerchTxnRef' => $migs_vars['vpc_MerchTxnRef'],
					'vpc_Merchant' =>  $migs_vars['vpc_Merchant'],
					'vpc_OrderInfo' => $migs_vars['vpc_OrderInfo'],
					'vpc_Amount' => $migs_vars['vpc_Amount'],
					'vpc_Locale' => $migs_vars['vpc_Locale'],
					'vpc_ReturnURL' => $migs_vars['vpc_ReturnURL']				
				
				);
				ksort ( $final );
				foreach( $final as $key => $value ) {
					if ( strlen( $value ) > 0 ) {
						if ( $appendAmp == 0 ) {
							$service_host .= urlencode( $key ) . '=' . urlencode( $value );
							$appendAmp = 1;
						} else {
							$service_host .= '&' . urlencode( $key ) . "=" . urlencode( $value );
						}
						$md5Hash .= $value;
					}
				}
				$migs_vars['vpc_SecureHash']=strtoupper( md5( $md5Hash ) );								
				$final['vpc_SecureHash']=strtoupper( md5( $md5Hash ) );								
				$migs_return = array('migs_url'=>$migs_url, 'migs_vars'=>$final);
				$return = array_merge($return, $migs_return);
				
				
			}else{
				//returning a free message
				$return['message'] = get_option('em_migs_booking_feedback_free');
			}
		}
		return $return;
	}
	
	/**
	 * Called if AJAX isn't being used, i.e. a javascript script failed and forms are being reloaded instead.
	 * @param string $feedback
	 * @return string
	 */
	function booking_form_feedback_fallback( $feedback ){
		global $EM_Booking;
		if( is_object($EM_Booking) ){
			$feedback .= "<br />" . __('To finalize your booking, please click the following button to proceed to Migs.','em-pro'). $this->em_my_bookings_booking_actions('',$EM_Booking);
		}
		return $feedback;
	}
	
	/**
	 * Triggered by the em_booking_add_yourgateway action, hooked in EM_Gateway. Overrides EM_Gateway to account for non-ajax bookings (i.e. broken JS on site).
	 * @param EM_Event $EM_Event
	 * @param EM_Booking $EM_Booking
	 * @param boolean $post_validation
	 */
	function booking_add($EM_Event, $EM_Booking, $post_validation = false){
		parent::booking_add($EM_Event, $EM_Booking, $post_validation);
		if( !defined('DOING_AJAX') ){ //we aren't doing ajax here, so we should provide a way to edit the $EM_Notices ojbect.
			add_action('option_dbem_booking_feedback', array(&$this, 'booking_form_feedback_fallback'));
		}
	}
	
	/* 
	 * --------------------------------------------------
	 * Booking UI - modifications to booking pages and tables containing migs bookings
	 * --------------------------------------------------
	 */
	
	/**
	 * Instead of a simple status string, a resume payment button is added to the status message so user can resume booking from their my-bookings page.
	 * @param string $message
	 * @param EM_Booking $EM_Booking
	 * @return string
	 */
	function em_my_bookings_booking_actions( $message, $EM_Booking){
	    global $wpdb;
		if($this->uses_gateway($EM_Booking) && $EM_Booking->booking_status == $this->status){
		    //first make sure there's no pending payments
		    $pending_payments = $wpdb->get_var('SELECT COUNT(*) FROM '.EM_TRANSACTIONS_TABLE. " WHERE booking_id='{$EM_Booking->booking_id}' AND transaction_gateway='{$this->gateway}' AND transaction_status='Pending'");
			
		    if( $pending_payments == 0 ){
				//user owes money!
				$paypal_vars = $this->get_migs_vars($EM_Booking);
				$md5Hash = "XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX";
				$final=array(	
					'vpc_Version' => $migs_vars['vpc_Version'],
					'vpc_Command' => $migs_vars['vpc_Command'],
					'vpc_AccessCode' => $migs_vars['vpc_AccessCode'],
					'vpc_MerchTxnRef' => $migs_vars['vpc_MerchTxnRef'],
					'vpc_Merchant' =>  $migs_vars['vpc_Merchant'],
					'vpc_OrderInfo' => $migs_vars['vpc_OrderInfo'],
					'vpc_Amount' => $migs_vars['vpc_Amount'],
					'vpc_Locale' => $migs_vars['vpc_Locale'],
					'vpc_ReturnURL' => $migs_vars['vpc_ReturnURL']				
				
				);
				ksort ( $final );
				foreach( $final as $key => $value ) {
					if ( strlen( $value ) > 0 ) {
						if ( $appendAmp == 0 ) {
							$service_host .= urlencode( $key ) . '=' . urlencode( $value );
							$appendAmp = 1;
						} else {
							$service_host .= '&' . urlencode( $key ) . "=" . urlencode( $value );
						}
						$md5Hash .= $value;
					}
				}
				$migs_vars['vpc_SecureHash']=strtoupper( md5( $md5Hash ) );								
				$final['vpc_SecureHash']=strtoupper( md5( $md5Hash ) );		
				$form = '<form action="'.$this->get_migs_url().'" method="post">';
				foreach($final as $key=>$value){
					$form .= '<input type="hidden" name="'.$key.'" value="'.$value.'" />';
				}
				$form .= '<input type="submit" value="'.__('Resume Payment','em-pro').'">';
				$form .= '</form>';
				$message .= $form;
		    }
		}
		return $message;		
	}

	/**
	 * Outputs extra custom content e.g. the Migs logo by default. 
	 */
	function booking_form(){
		echo get_option('em_'.$this->gateway.'_form');
	}
	
	/**
	 * Outputs some JavaScript during the em_gateway_js action, which is run inside a script html tag, located in gateways/gateway.migs.js
	 */
	function em_gateway_js(){
		include(dirname(__FILE__).'/gateway.migs.js');		
	}
	
	/**
	 * Adds relevant actions to booking shown in the bookings table
	 * @param EM_Booking $EM_Booking
	 */
	function bookings_table_actions( $actions, $EM_Booking ){
		return array(
			'approve' => '<a class="em-bookings-approve em-bookings-approve-offline" href="'.em_add_get_params($_SERVER['REQUEST_URI'], array('action'=>'bookings_approve', 'booking_id'=>$EM_Booking->booking_id)).'">'.esc_html__emp('Approve','dbem').'</a>',
			'delete' => '<span class="trash"><a class="em-bookings-delete" href="'.em_add_get_params($_SERVER['REQUEST_URI'], array('action'=>'bookings_delete', 'booking_id'=>$EM_Booking->booking_id)).'">'.esc_html__emp('Delete','dbem').'</a></span>',
			'edit' => '<a class="em-bookings-edit" href="'.em_add_get_params($EM_Booking->get_event()->get_bookings_url(), array('booking_id'=>$EM_Booking->booking_id, 'em_ajax'=>null, 'em_obj'=>null)).'">'.esc_html__emp('Edit/View','dbem').'</a>',
		);
	}
	
	/*
	 * --------------------------------------------------
	 * Migs Functions - functions specific to migs payments
	 * --------------------------------------------------
	 */
	
	/**
	 * Retreive the migs vars needed to send to the gatway to proceed with payment
	 * @param EM_Booking $EM_Booking
	 */
	function get_migs_vars($EM_Booking){
		global $wp_rewrite, $EM_Notices;
		$notify_url = $this->get_payment_return_url();
			
		$migs_vars = array(
			'business' => get_option('em_'. $this->gateway . "_email" ), 
			'cmd' => '_cart',
			'upload' => 1,
			'currency_code' => get_option('dbem_bookings_currency', 'USD'),
			'notify_url' =>$notify_url,
			'custom' => $EM_Booking->booking_id.':'.$EM_Booking->event_id,
			'charset' => 'UTF-8',
		    'bn'=>'NetWebLogic_SP',
			'vpc_Version' => '1',
			'vpc_Command' => 'pay',
			'vpc_AccessCode' => 'XXXXXXXXXXX',
			'vpc_MerchTxnRef' => $EM_Booking->booking_id.'_'.$EM_Booking->event_id.'_'.date("ymds").'CCCEVENTS',
			'vpc_Merchant' =>  '0000000000000',
			'vpc_OrderInfo' => $EM_Booking->booking_id.'_'.$EM_Booking->event_id.'_'.date("ymds"),
			'vpc_Amount' => 10000,
			'vpc_Locale' => 'en',
			'vpc_ReturnURL' => $notify_url
		);
		if( get_option('em_'. $this->gateway . "_lc" ) ){
		    $migs_vars['lc'] = get_option('em_'. $this->gateway . "_lc" );
		}
		$migs_vars['vpc_SecureHash']='test';	
		//address fields`and name/email fields to prefill on checkout page (if available)
		$migs_vars['email'] = $EM_Booking->get_person()->user_email;
		$migs_vars['first_name'] = $EM_Booking->get_person()->first_name;
		$migs_vars['last_name'] = $EM_Booking->get_person()->last_name;
        if( EM_Gateways::get_customer_field('address', $EM_Booking) != '' ) $migs_vars['address1'] = EM_Gateways::get_customer_field('address', $EM_Booking);
        if( EM_Gateways::get_customer_field('address_2', $EM_Booking) != '' ) $migs_vars['address2'] = EM_Gateways::get_customer_field('address_2', $EM_Booking);
        if( EM_Gateways::get_customer_field('city', $EM_Booking) != '' ) $migs_vars['city'] = EM_Gateways::get_customer_field('city', $EM_Booking);
        if( EM_Gateways::get_customer_field('state', $EM_Booking) != '' ) $migs_vars['state'] = EM_Gateways::get_customer_field('state', $EM_Booking);
        if( EM_Gateways::get_customer_field('zip', $EM_Booking) != '' ) $migs_vars['zip'] = EM_Gateways::get_customer_field('zip', $EM_Booking);
        if( EM_Gateways::get_customer_field('country', $EM_Booking) != '' ) $migs_vars['country'] = EM_Gateways::get_customer_field('country', $EM_Booking);
        
		//tax is added regardless of whether included in ticket price, otherwise we can't calculate post/pre tax discounts
		if( $EM_Booking->get_price_taxes() > 0 && !get_option('em_'. $this->gateway . "_inc_tax" ) ){ 
			$migs_vars['tax_cart'] = round($EM_Booking->get_price_taxes(), 2);
		}
		if( get_option('em_'. $this->gateway . "_return" ) != "" ){
			$migs_vars['return'] = get_option('em_'. $this->gateway . "_return" );
		}
		if( get_option('em_'. $this->gateway . "_cancel_return" ) != "" ){
			$migs_vars['cancel_return'] = get_option('em_'. $this->gateway . "_cancel_return" );
		}
		if( get_option('em_'. $this->gateway . "_format_logo" ) !== false ){
			$migs_vars['cpp_logo_image'] = get_option('em_'. $this->gateway . "_format_logo" );
		}
		if( get_option('em_'. $this->gateway . "_border_color" ) !== false ){
			$migs_vars['cpp_cart_border_color'] = get_option('em_'. $this->gateway . "_format_border" );
		}
		$count = 1;
		foreach( $EM_Booking->get_tickets_bookings()->tickets_bookings as $EM_Ticket_Booking ){ /* @var $EM_Ticket_Booking EM_Ticket_Booking */
		    //divide price by spaces for per-ticket price
		    //we divide this way rather than by $EM_Ticket because that can be changed by user in future, yet $EM_Ticket_Booking will change if booking itself is saved.
		    if( !get_option('em_'. $this->gateway . "_inc_tax" ) ){
		    	$price = $EM_Ticket_Booking->get_price() / $EM_Ticket_Booking->get_spaces();
		    }else{
		    	$price = $EM_Ticket_Booking->get_price_with_taxes() / $EM_Ticket_Booking->get_spaces();
		    }
			if( $price > 0 ){
				$migs_vars['item_name_'.$count] = wp_kses_data($EM_Ticket_Booking->get_ticket()->name);
				$migs_vars['quantity_'.$count] = $EM_Ticket_Booking->get_spaces();
				$migs_vars['amount_'.$count] = round($price,2);
				$count++;
			}
		}
		$amount=0;
		foreach( $EM_Booking->get_tickets_bookings()->tickets_bookings as $EM_Ticket_Booking ){
				$amount=$amount+$EM_Ticket_Booking->get_price_with_taxes();
			}
		//calculate discounts, if any:
		$discount = $EM_Booking->get_price_discounts_amount('pre') + $EM_Booking->get_price_discounts_amount('post');
			if( $discount > 0 ){
				$migs_vars['discount_amount_cart'] = $discount;
				$amount = $amount-$discount;
			}
		
		$order_amount = 100 * $amount;	
		$migs_vars['vpc_Amount']=$order_amount;
		return apply_filters('em_gateway_migs_get_migs_vars', $migs_vars, $EM_Booking, $this);
	}
	
	/**
	 * gets migs gateway url (sandbox or live mode)
	 * @returns string 
	 */
	function get_migs_url(){
		return ( get_option('em_'. $this->gateway . "_status" ) == 'test') ? 'https://migs.mastercard.com.au/vpcpay':'https://migs.mastercard.com.au/vpcpay';
	}
	
	function say_thanks(){
		if( !empty($_REQUEST['thanks']) ){
			echo "<div class='em-booking-message em-booking-message-success'>".get_option('em_'.$this->gateway.'_booking_feedback_completed').'</div>';
		}
	}

/**
	 * Runs when migs sends IPNs to the return URL provided during bookings and EM setup. Bookings are updated and transactions are recorded accordingly. 
	 */
	function handle_payment_return(){
			global $wpdb;
			$authorised = false;
			
			$md5Hash = "XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX";
			$txnSecureHash = $_REQUEST['vpc_SecureHash'];	
			$info=$this->responseDescription($_REQUEST['vpc_TxnResponseCode']);			
			$order_id = explode( '_', $_REQUEST['vpc_MerchTxnRef'] );
			$amount=number_format((float)($_REQUEST['vpc_Amount']/100), 2, '.', '');
			$order_id = $order_id[0];
			$EM_Booking = em_get_booking($order_id);
			$event_id = !empty($order_id[1]) ? $order_id[1]:0;
			
			$DR = $this->parseDigitalReceipt();
			$refer=$DR['merchTxnRef'];
			$ThreeDSecureData = $this->parse3DSecureData();			
			
			$msg['class']   = 'error';
			$msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
			
			if ( strlen($md5Hash) > 0 && $_REQUEST['vpc_TxnResponseCode'] != "7" && $_REQUEST['vpc_TxnResponseCode'] != "No Value Returned") {
				
			
				foreach( $_REQUEST as $key => $value ) {
					if($key != "vpc_SecureHash" || $key!='action' || $key!='em_payment_gateway')
					{
						if (strlen( $value ) > 0) {
							$md5Hash .= $value;
						}
					}
				}
			
				/*if ( strtoupper( $txnSecureHash ) != strtoupper( md5( $md5Hash )) ) {
					$authorised = false;
				} else {	*/				
					if( $DR["txnResponseCode"] == "0" ) {									
						$authorised = true;
					} else {
						$authorised = false;			
						
					}
				//}
				
			
			} else {
				$authorised = false;
			}
			
			if( $authorised ) {
				try {
					@set_time_limit(60);
					//$amount=$ThreeDSecureData['amount'];
					if( !empty($EM_Booking->booking_id) ){
						//$this->record_transaction($EM_Booking, $amount, 'LKR', current_time('mysql'), $_POST['vpc_MerchTxnRef'], __('Completed','em-pro'), '');
						$this->record_transaction($EM_Booking, $amount, 'LKR', current_time('mysql'), $order_id,  __('Completed','em-pro'), '');
						if( !get_option('em_'.$this->gateway.'_manual_approval', false) || !get_option('dbem_bookings_approval')) {
							$EM_Booking->approve(true, true); 
						}//approve and ignore spaces
						do_action('em_payment_processed', $EM_Booking, $this);	
						$url = home_url( $path = 'thank-you', $scheme = relative );	
						header('Location:'.$url."/?result=".$_REQUEST['vpc_TxnResponseCode']."&amount=".$DR['amount']."&order=".$DR["orderInfo"]."&receipt=".$DR["receiptNo"]."&info=".$info);						
						//echo "Transaction Processed";
						//header( "refresh:5;url=".$url );
						// header("Content-type: text/plain");
						// header("Content-Disposition: attachment; filename=yourreceipt.txt;");
						// print "This is your receipt";
						// print $content;
						exit();
						//wp_redirect( $url ); exit;						
						//echo "<script>window.location = '".$url."'</script>";						
						
						
					}
					else{
						$txn = $wpdb->get_row( $wpdb->prepare( "SELECT transaction_id, transaction_gateway_id, transaction_total_amount, booking_id FROM ".EM_TRANSACTIONS_TABLE." WHERE transaction_gateway_id = %s AND transaction_gateway = %s ORDER BY transaction_total_amount DESC LIMIT 1", $order_id, $this->gateway ), ARRAY_A );
						if( is_array($txn) && $txn['transaction_gateway'] == 'migs' && !empty($txn['booking_id']) ){
							$EM_Booking = em_get_booking($txn['booking_id']);
							$EM_Booking->cancel();
							$wpdb->update(EM_TRANSACTIONS_TABLE, array('transaction_status'=>__('Voided','em-pro'),'transaction_timestamp'=>current_time('mysql')), array('transaction_id'=>$order_id));
							echo "Transaction Processed";
						}
						else{
							echo "Transaction not found"; //meaningful output
						}
						echo "Transaction not found"; 
						
					}
					//meaningful output
	        	}				
				catch( Exception $e ) {
					echo "Unprocessed transaction - ".$this->title;
				}
			} 
			else {
				try {
					@set_time_limit(60);
				//update_option('silent_post',$_POST); //for debugging, could be removed, but useful since aim provides no history on this
				$txn = $wpdb->get_row( $wpdb->prepare( "SELECT transaction_id, transaction_gateway_id, transaction_total_amount, booking_id FROM ".EM_TRANSACTIONS_TABLE." WHERE transaction_gateway_id = %s AND transaction_gateway = %s ORDER BY transaction_total_amount DESC LIMIT 1", $refer, $this->gateway ), ARRAY_A );
						if( is_array($txn) && $txn['transaction_gateway'] == 'migs' && !empty($txn['booking_id']) ){
							$EM_Booking = em_get_booking($txn['booking_id']);
							$EM_Booking->cancel();
							$wpdb->update(EM_TRANSACTIONS_TABLE, array('transaction_status'=>__('Error_Occurred','em-pro'),'transaction_timestamp'=>current_time('mysql')), array('transaction_id'=>$refer));
				
						}
						$url = home_url( $path = 'thank-you', $scheme = relative );	
						header('Location:'.$url."/?result=".$_REQUEST['vpc_TxnResponseCode']."&amount=".$DR['amount']."&order=".$DR["orderInfo"]."&receipt=".$DR["receiptNo"]."&info=".$info);
				// echo "authorization failed".responseDescription( $DR["txnResponseCode"] );
				// $url = home_url( $path = 'events-archive', $scheme = relative );	
				// header('Location:'.$url."/?no=".$DR['receiptNo']."&amount=".$DR['amount']);	
				}
				catch( Exception $e ) {
					echo "Unprocessed transaction - ".$this->title;
				}
			}			
		}
		
		private function responseDescription( $responseCode ) {
			switch ( $responseCode ) {
				case "0" : $result = "Transaction Successful"; break;
				case "?" : $result = "Transaction status is unknown"; break;
				case "1" : $result = "Unknown Error"; break;
				case "2" : $result = "Bank Declined Transaction"; break;
				case "3" : $result = "No Reply from Bank"; break;
				case "4" : $result = "Expired Card"; break;
				case "5" : $result = "Insufficient funds"; break;
				case "6" : $result = "Error Communicating with Bank"; break;
				case "7" : $result = "Payment Server System Error"; break;
				case "8" : $result = "Transaction Type Not Supported"; break;
				case "9" : $result = "Bank declined transaction (Do not contact Bank)"; break;
				case "A" : $result = "Transaction Aborted"; break;
				case "C" : $result = "Transaction Cancelled"; break;
				case "D" : $result = "Deferred transaction has been received and is awaiting processing"; break;
				case "F" : $result = "3D Secure Authentication failed"; break;
				case "I" : $result = "Card Security Code verification failed"; break;
				case "L" : $result = "Shopping Transaction Locked (Please try the transaction again later)"; break;
				case "N" : $result = "Cardholder is not enrolled in Authentication scheme"; break;
				case "P" : $result = "Transaction has been received by the Payment Adaptor and is being processed"; break;
				case "R" : $result = "Transaction was not processed - Reached limit of retry attempts allowed"; break;
				case "S" : $result = "Duplicate SessionID (OrderInfo)"; break;
				case "T" : $result = "Address Verification Failed"; break;
				case "U" : $result = "Card Security Code Failed"; break;
				case "V" : $result = "Address Verification and Card Security Code Failed"; break;
				default  : $result = "Unable to be determined";
			}
			return $result;
		}
		private function parse3DSecureData() {
			$threeDSecure = array(
				"verType"         	=> array_key_exists( "vpc_VerType", $_REQUEST )          ? $_REQUEST['vpc_VerType']          : "No Value Returned",
				"verStatus"       	=> array_key_exists( "vpc_VerStatus", $_REQUEST )        ? $_REQUEST['vpc_VerStatus']        : "No Value Returned",
				"token"           	=> array_key_exists( "vpc_VerToken", $_REQUEST )         ? $_REQUEST['vpc_VerToken']         : "No Value Returned",
				"verSecurLevel"   	=> array_key_exists( "vpc_VerSecurityLevel", $_REQUEST ) ? $_REQUEST['vpc_VerSecurityLevel'] : "No Value Returned",
				"enrolled"        	=> array_key_exists( "vpc_3DSenrolled", $_REQUEST )      ? $_REQUEST['vpc_3DSenrolled']      : "No Value Returned",
				"xid"             	=> array_key_exists( "vpc_3DSXID", $_REQUEST )           ? $_REQUEST['vpc_3DSXID']           : "No Value Returned",
				"acqECI"          	=> array_key_exists( "vpc_3DSECI", $_REQUEST )           ? $_REQUEST['vpc_3DSECI']           : "No Value Returned",
				"authStatus"      	=> array_key_exists( "vpc_3DSstatus", $_REQUEST )        ? $_REQUEST['vpc_3DSstatus']        : "No Value Returned"
			);
			return $threeDSecure;
		}
		private function parseDigitalReceipt() {
			$dReceipt = array(
				"amount" 			=> $this->null2unknown( $_REQUEST['vpc_Amount'] ),
				"locale"          	=> $this->null2unknown( $_REQUEST['vpc_Locale'] ),
				"batchNo"         	=> $this->null2unknown( $_REQUEST['vpc_BatchNo'] ),
				"command"         	=> $this->null2unknown( $_REQUEST['vpc_Command'] ),
				"message"         	=> $this->null2unknown( $_REQUEST['vpc_Message'] ),
				"version"         	=> $this->null2unknown( $_REQUEST['vpc_Version'] ),
				"cardType"        	=> $this->null2unknown( $_REQUEST['vpc_Card'] ),
				"orderInfo"       	=> $this->null2unknown( $_REQUEST['vpc_OrderInfo'] ),
				"receiptNo"       	=> $this->null2unknown( $_REQUEST['vpc_ReceiptNo'] ),
				"merchantID"      	=> $this->null2unknown( $_REQUEST['vpc_Merchant'] ),
				"authorizeID"     	=> $this->null2unknown( $_REQUEST['vpc_AuthorizeId'] ),
				"merchTxnRef"     	=> $this->null2unknown( $_REQUEST['vpc_MerchTxnRef'] ),
				"transactionNo"   	=> $this->null2unknown( $_REQUEST['vpc_TransactionNo'] ),
				"acqResponseCode" 	=> $this->null2unknown( $_REQUEST['vpc_AcqResponseCode'] ),
				"txnResponseCode" 	=> $this->null2unknown( $_REQUEST['vpc_TxnResponseCode'] )
			);
			return $dReceipt;
		}
		/**
		* Handle null values
		*/
		private function null2unknown($data) {
			if ($data == "") {
				return "No Value Returned";
			} else {
				return $data;
			}
		}
	
	/**
	 * Fixes SSL issues with wamp and outdated server installations combined with curl requests by forcing a custom pem file, generated from - http://curl.haxx.se/docs/caextract.html
	 * @param resource $handle
	 */
	public static function payment_return_local_ca_curl( $handle ){
	    curl_setopt($handle, CURLOPT_CAINFO, dirname(__FILE__).DIRECTORY_SEPARATOR.'gateway.migs.pem');
	}
	
	/*
	 * --------------------------------------------------
	 * Gateway Settings Functions
	 * --------------------------------------------------
	 */
	
	/**
	 * Outputs custom Migs setting fields in the settings page 
	 */
	function mysettings() {
		global $EM_options;
		?>
		<table class="form-table">
		<tbody>
		  <?php em_options_input_text( esc_html__('Success Message', 'em-pro'), 'em_'. $this->gateway . '_booking_feedback', esc_html__('The message that is shown to a user when a booking is successful whilst being redirected to Migs for payment.','em-pro') ); ?>
		  <?php em_options_input_text( esc_html__('Success Free Message', 'em-pro'), 'em_'. $this->gateway . '_booking_feedback_free', esc_html__('If some cases if you allow a free ticket (e.g. pay at gate) as well as paid tickets, this message will be shown and the user will not be redirected to Migs.','em-pro') ); ?>
		  <?php em_options_input_text( esc_html__('Thank You Message', 'em-pro'), 'em_'. $this->gateway . '_booking_feedback_completed', esc_html__('If you choose to return users to the default Events Manager thank you page after a user has paid on Migs, you can customize the thank you message here.','em-pro') ); ?>
		</tbody>
		</table>
		
		<h3><?php echo sprintf(__('%s Options','em-pro'),'Migs'); ?></h3>
		<p><strong><?php _e('Important:','em-pro'); ?></strong> <?php echo __('In order to connect Migs with your site, you need to enable IPN on your account.'); echo " ". sprintf(__('Your return url is %s','em-pro'),'<code>'.$this->get_payment_return_url().'</code>'); ?></p> 
		<p><?php echo sprintf(__('Please visit the <a href="%s">documentation</a> for further instructions.','em-pro'), 'http://wp-events-plugin.com/documentation/'); ?></p>
		<table class="form-table">
		<tbody>
		  <tr valign="top">
			  <th scope="row"><?php _e('Migs Merchant ID', 'em-pro') ?></th>
				  <td><input type="text" name="em_migs_em" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_merchantID" )); ?>" />
				  <br />
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Migs Access Code', 'em-pro') ?></th>
				  <td><input type="text" name="em_migs_ac" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_accessCode" )); ?>" />
				  <br />
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Migs Secure Hash', 'em-pro') ?></th>
				  <td><input type="text" name="em_migs_sh" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_secureHash" )); ?>" />
				  <br />
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Migs Currency', 'em-pro') ?></th>
			  <td><?php echo esc_html(get_option('dbem_bookings_currency','LKR')); ?><br /><i><?php echo sprintf(__('Set your currency in the <a href="%s">settings</a> page.','em-pro'),EM_ADMIN_URL.'&amp;page=events-manager-options#bookings'); ?></i></td>
		  </tr>
		  
		  <?php em_options_radio_binary(__('Include Taxes In Itemized Prices', 'em-pro'), 'em_'. $this->gateway .'_inc_tax', __('If set to yes, taxes are not included in individual item prices and total tax is shown at the bottom. If set to no, taxes are included within the individual prices.','em-pro'). ' '. __('We strongly recommend setting this to No.','em-pro') .' <a href="http://wp-events-plugin.com/documentation/events-with-migs/migs-displaying-taxes/">'. __('Click here for more information.','em-pro')) .'</a>'; ?>
		  
		  <tr valign="top">
			  <th scope="row"><?php _e('Migs Language', 'em-pro') ?></th>
			  <td>
			  	<select name="em_migs_lc">
			  		<option value=""><?php _e('Default','em-pro'); ?></option>
				  <?php
					$ccodes = em_get_countries();
					$migs_lc = get_option('em_'.$this->gateway.'_lc', 'US');
					foreach($ccodes as $key => $value){
						if( $migs_lc == $key ){
							echo '<option value="'.$key.'" selected="selected">'.$value.'</option>';
						}else{
							echo '<option value="'.$key.'">'.$value.'</option>';
						}
					}
				  ?>
				  
				  </select>
				  <br />
				  <i><?php _e('Migs allows you to select a default language users will see. This is also determined by Migs which detects the locale of the users browser. The default would be US.','em-pro') ?></i>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Migs Mode', 'em-pro') ?></th>
			  <td>
				  <select name="em_migs_status">
					  <option value="live" <?php if (get_option('em_'. $this->gateway . "_status" ) == 'live') echo 'selected="selected"'; ?>><?php _e('Live Site', 'em-pro') ?></option>
					  <option value="test" <?php if (get_option('em_'. $this->gateway . "_status" ) == 'test') echo 'selected="selected"'; ?>><?php _e('Test Mode (Sandbox)', 'em-pro') ?></option>
				  </select>
				  <br />
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Return URL', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="em_migs_return" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_return" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('Once a payment is completed, users will be offered a link to this URL which confirms to the user that a payment is made. If you would to customize the thank you page, create a new page and add the link here. For automatic redirect, you need to turn auto-return on in your Migs settings.', 'em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Cancel URL', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="em_migs_cancel_return" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_cancel_return" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('Whilst paying on Migs, if a user cancels, they will be redirected to this page.', 'em-pro'); ?></em>
			  </td>
		  </tr>
		  <!--<tr valign="top">
			  <th scope="row"><?php //_e('Migs Page Logo', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="em_migs_format_logo" value="<?php //esc_attr_e(get_option('em_'. $this->gateway . "_format_logo" )); ?>" style='width: 40em;' /><br />
			  	<em><?php //_e('Add your logo to the Migs payment page. It\'s highly recommended you link to a https:// address.', 'em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php //_e('Border Color', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="em_migs_format_border" value="<?php //esc_attr_e(get_option('em_'. $this->gateway . "_format_border" )); ?>" style='width: 40em;' /><br />
			  	<em><?php //_e('Provide a hex value color to change the color from the default blue to another color (e.g. #CCAAAA).','em-pro'); ?></em>
			  </td>
		  </tr>-->
		  <tr valign="top">
			  <th scope="row"><?php _e('Delete Bookings Pending Payment', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="em_migs_booking_timeout" style="width:50px;" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_timeout" )); ?>" style='width: 40em;' /> <?php _e('minutes','em-pro'); ?><br />
			  	<em><?php _e('Once a booking is started and the user is taken to Migs, Events Manager stores a booking record in the database to identify the incoming payment. These spaces may be considered reserved if you enable <em>Reserved unconfirmed spaces?</em> in your Events &gt; Settings page. If you would like these bookings to expire after x minutes, please enter a value above (note that bookings will be deleted, and any late payments will need to be refunded manually via Migs).','em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Manually approve completed transactions?', 'em-pro') ?></th>
			  <td>
			  	<input type="checkbox" name="em_migs_manual_approval" value="1" <?php echo (get_option('em_'. $this->gateway . "_manual_approval" )) ? 'checked="checked"':''; ?> /><br />
			  	<em><?php _e('By default, when someone pays for a booking, it gets automatically approved once the payment is confirmed. If you would like to manually verify and approve bookings, tick this box.','em-pro'); ?></em><br />
			  	<em><?php echo sprintf(__('Approvals must also be required for all bookings in your <a href="%s">settings</a> for this to work properly.','em-pro'),EM_ADMIN_URL.'&amp;page=events-manager-options'); ?></em>
			  </td>
		  </tr>
		</tbody>
		</table>
		<?php
	}

	/* 
	 * Run when saving Migs settings, saves the settings available in EM_Gateway_Migs::mysettings()
	 */
	function update() {
	    $gateway_options = $options_wpkses = array();
		//$gateway_options[] = 'em_'. $this->gateway . '_email';
		$gateway_options[] = 'em_'. $this->gateway . '_merchantID';
		$gateway_options[] = 'em_'. $this->gateway . '_accessCode';
		$gateway_options[] = 'em_'. $this->gateway . '_secureHash';
		$gateway_options[] = 'em_'. $this->gateway . '_site';
		$gateway_options[] = 'em_'. $this->gateway . '_currency';
		$gateway_options[] = 'em_'. $this->gateway . '_inc_tax';
		$gateway_options[] = 'em_'. $this->gateway . '_lc';
		$gateway_options[] = 'em_'. $this->gateway . '_status';
		//$gateway_options[] = 'em_'. $this->gateway . '_format_logo';
		//$gateway_options[] = 'em_'. $this->gateway . '_format_border';
		$gateway_options[] = 'em_'. $this->gateway . '_manual_approval';
		$gateway_options[] = 'em_'. $this->gateway . '_booking_timeout';
		$gateway_options[] = 'em_'. $this->gateway . '_return';
		$gateway_options[] = 'em_'. $this->gateway . '_cancel_return';
		//add wp_kses filters for relevant options and merge in
		$options_wpkses[] = 'em_'. $this->gateway . '_booking_feedback';
		$options_wpkses[] = 'em_'. $this->gateway . '_booking_feedback_free';
		$options_wpkses[] = 'em_'. $this->gateway . '_booking_feedback_completed';
		foreach( $options_wpkses as $option_wpkses ) add_filter('gateway_update_'.$option_wpkses,'wp_kses_post');
		$gateway_options = array_merge($gateway_options, $options_wpkses);
		//pass options to parent which handles saving
		return parent::update($gateway_options);
	}
}
EM_Gateways::register_gateway('migs', 'EM_Gateway_Migs');

/**
 * Deletes bookings pending payment that are more than x minutes old, defined by migs options. 
 */
function em_gateway_migs_booking_timeout(){
	global $wpdb;
	//Get a time from when to delete
	$minutes_to_subtract = absint(get_option('em_migs_booking_timeout'));
	if( $minutes_to_subtract > 0 ){
		//get booking IDs without pending transactions
		$cut_off_time = date('Y-m-d H:i:s', current_time('timestamp') - ($minutes_to_subtract * 60));
		$booking_ids = $wpdb->get_col('SELECT b.booking_id FROM '.EM_BOOKINGS_TABLE.' b LEFT JOIN '.EM_TRANSACTIONS_TABLE." t ON t.booking_id=b.booking_id  WHERE booking_date < '{$cut_off_time}' AND booking_status=4 AND transaction_id IS NULL AND booking_meta LIKE '%s:7:\"gateway\";s:6:\"migs\";%'" );
		if( count($booking_ids) > 0 ){
			//first delete ticket_bookings with expired bookings
			foreach( $booking_ids as $booking_id ){
			    $EM_Booking = em_get_booking($booking_id);
			    $EM_Booking->manage_override = true;
			    $EM_Booking->delete();
			}
		}
	}
}
add_action('emp_migs_cron', 'em_gateway_migs_booking_timeout');
}
add_action('plugins_loaded','migs_payment_gateway',100);