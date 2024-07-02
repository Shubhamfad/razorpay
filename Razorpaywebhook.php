<?php
defined('BASEPATH') OR exit('No direct script access allowed');
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

class Razorpaywebhook extends CI_Controller
{

	public function index(){
       
        $attributes = file_get_contents("php://input");
        
        $data = json_decode($attributes, true);
        
        $amount = $data['payload']['payment']['entity']['amount'];
        $description = $data['payload']['payment']['entity']['description'];
        $method = $data['payload']['payment']['entity']['method'];
        $contact = $data['payload']['payment']['entity']['contact'];
        $email = $data['payload']['payment']['entity']['email'];
        $captured = $data['payload']['payment']['entity']['captured'];
        $order = $data['payload']['payment']['entity']['order_id'];
        $payment_id = $data['payload']['payment']['entity']['id'];
        
        
        $this->load->model('Funder_model', 'Funder');
        unset($_POST);
        if($data['event'] == 'payment.failed'){
            
            $pre_funding_details = $this->Funder->get_pre_funding_data_by_request_id($order);
            $project_id =  $pre_funding_details['fl_project_id'];
            $user_id = $pre_funding_details['fl_funder_unique_user_id'];

            $payment_status = 0;
            $_POST['fpi_card_type'] = $pre_funding_details['fl_card_type'];
            $_POST['fpi_amount_funded'] = $pre_funding_details['fl_funded_amount'];
            $_POST['fpi_payment_id'] = $payment_id;
            $_POST['fpi_payment_mode'] = $method;
            $_POST['fpi_transaction_status'] = $payment_status;
            $_POST['fpi_merchant_id'] = $pre_funding_details['fl_project_id'];
            $_POST['fpi_email'] = $email;
            $_POST['fpi_mobile'] = $contact;
            $_POST['fpi_user_id'] = $pre_funding_details['fl_funder_unique_user_id'];
            $_POST['fpi_project_id'] = $pre_funding_details['fl_project_id'];
            $_POST['device_info'] = json_encode($_SERVER);
            $_POST['fpi_hash_payu'] = substr($this->getToken(),0,-8).'-'.substr($this->getToken(),0,-8).'-'.substr($this->getToken(),0,-8);
            $_POST['fpi_transaction_id_gen'] = $pre_funding_details['fl_id'];
            
            $_POST['fpi_matching_partner_amount'] = add_matching_partner_contribution($description, $pre_funding_details['fl_funded_amount']);

            $value = $this->Funder->payment_id_duplicate_check($_POST['fpi_payment_id']);
        
            if($value['result_count'] == 0){
                $value['fpi_id'] = $this->Funder->add_confirmed_funding_details($_POST);
            }
            if($payment_status == 0){

                $data_array_fl['fl_payment_confirmation'] = $payment_status;
                $data_array_fpi['fpi_transaction_status'] = $payment_status;

                $this->db->update('fad_funders_list', $data_array_fl, array('fl_id' => $pre_funding_details['fl_id']));
                
                $this->db->update('fad_funder_payment_information', $data_array_fpi, array('fpi_id' => $value['fpi_id']));
            }
            $this->razorpay_payment_fail($project_id,$user_id);
        }
        elseif($data['event'] == 'payment.captured'){
            
            $pre_funding_details = $this->Funder->get_pre_funding_data_by_request_id($order);
            $payment_status = 1;
            $_POST['fpi_card_type'] = $pre_funding_details['fl_card_type'];
            $_POST['fpi_amount_funded'] = $pre_funding_details['fl_funded_amount'];
            $_POST['fpi_payment_id'] = $payment_id;
            $_POST['fpi_payment_mode'] = $method;
            $_POST['fpi_transaction_status'] = $payment_status;
            $_POST['fpi_merchant_id'] = $pre_funding_details['fl_project_id'];
            $_POST['fpi_email'] = $email;
            $_POST['fpi_mobile'] = $contact;
            $_POST['fpi_user_id'] = $pre_funding_details['fl_funder_unique_user_id'];
            $_POST['fpi_project_id'] = $pre_funding_details['fl_project_id'];
            $_POST['device_info'] = json_encode($_SERVER);
            $_POST['fpi_hash_payu'] = substr($this->getToken(),0,-8).'-'.substr($this->getToken(),0,-8).'-'.substr($this->getToken(),0,-8);
            $_POST['fpi_transaction_id_gen'] = $pre_funding_details['fl_id'];
            
            $_POST['fpi_matching_partner_amount'] = add_matching_partner_contribution($data['purpose'], $pre_funding_details['fl_funded_amount']);

            $value = $this->Funder->payment_id_duplicate_check($_POST['fpi_payment_id']);
        
            if($value['result_count'] == 0){
                $value['fpi_id'] = $this->Funder->add_confirmed_funding_details($_POST);
            }
            if($payment_status == 1){

                $data_array_fl['fl_payment_confirmation'] = $payment_status;
                $data_array_fpi['fpi_transaction_status'] = $payment_status;

                $this->db->update('fad_funders_list', $data_array_fl, array('fl_id' => $pre_funding_details['fl_id']));
                
                $this->db->update('fad_funder_payment_information', $data_array_fpi, array('fpi_id' => $value['fpi_id']));

                redirect('payment_flow/razorpay_payment_confirmation/'.$pre_funding_details['fl_project_id']);
            }
        }elseif($data['event'] == 'refund.created'){
            
        }
        
	}
    
    public function getToken(){
        $token = '';
        $codeAlphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $codeAlphabet .= 'abcdefghijklmnopqrstuvwxyz';
        $codeAlphabet .= '0123456789';
        $max = strlen($codeAlphabet); // edited

        for ($i = 0; $i < 4; ++$i) {
        $token .= $codeAlphabet[$this->crypto_rand_secure(0, $max - 1)];
        $token .= $codeAlphabet[$this->crypto_rand_secure(0, $max - 1)];
        $token .= $codeAlphabet[$this->crypto_rand_secure(0, $max - 1)];
        }

        return $token;
    }

    public function crypto_rand_secure($min, $max){
        $range = $max - $min;
        if ($range < 1) {
            return $min;
        } // not so random...
        $log = ceil(log($range, 2));
        $bytes = (int) ($log / 8) + 1; // length in bytes
        $bits = (int) $log + 1; // length in bits
        $filter = (int) (1 << $bits) - 1; // set all lower bits to 1
        do {
            $rnd = hexdec(bin2hex(openssl_random_pseudo_bytes($bytes)));
            $rnd = $rnd & $filter; // discard irrelevant bits
        } while ($rnd > $range);

        return $min + $rnd;
    }

    public function razorpay_payment_fail($project_id,$user_id) {
 
        $this->load->model('Projectmodel','project');
        $this->load->model('Funder_model','funder');
        $this->load->model('Usermodel','user');
        $data = array();
            
        
        $data['user_id'] = $user_id;
        $data['user_info'] = $this->user->get_user_info($data['user_id']);
        $data['user_type'] = $data['user_info']['user_type'];
        $data['project_id'] = $project_id;
        
        $fname = $data['user_info']['fname'];
        $lname = $data['user_info']['lname'];
        
        $to_title = trim($data['user_info']['email']);
    
        $data['project_info'] = $this->project->get_project_details($project_id); 
        
        $campaign_title = strtoupper($data['project_info']['name']);
        $camp_id = $data['project_info']['id'];
    
        $data['payment_details'] = $this->funder->get_payment_details($project_id,$data['user_id']);
    
        $email_array = array(
        'fname'                 => $fname,
        'lname'                 => $lname,
        'campaign_title'        => $campaign_title,
        'camp_id'               => $project_id,
        'cate_id'               => $data['project_info']['cate_id'],  
        'fpi_hash_payu'         => $data['payment_details']['fpi_hash_payu'],
        'size'                  => $data['payment_details']['fl_size'],
        'meterial'              => $data['payment_details']['fl_meterial'],
        'color'                 => ucwords(str_replace('_',' ',$data['payment_details']['fl_color']))
        ); 
    
        $data['rating']  = $this->funder->get_rating($project_id,$data['user_id']);
    
        if($project_id == 89 && $this->session->userdata('reward_id') != 525 && $this->session->userdata('reward_id') != 561){
        $data['display_link_linen'] = $this->funder->get_display_link($project_id,$this->session->userdata('reward_id'));
        }
    
        delete_cookie('funder_payment_id');
        delete_cookie('anony_id');
        delete_cookie('amount_funded');
    
        // payment_id_exists  
        /* Reason : For duplicate Check */
        $value = $this->payment_id_exists($data['payment_details']['fpi_payment_id']);
    
        if($value['result_count'] == 0){
        
        /*** SEND CONFIRMATION OTP MESSAGE **/
        
        $user_id = $data['user_id'];
        $code = $data['user_info']['country_code'];
        $mobile_number = $data['user_info']['mobile'];
        $email = $data['user_info']['email'];
        $xmobile_number = $code.$mobile_number;
        
        if ($data['project_info']['cate_id']==12 || $data['project_info']['cate_id']==66) {
            $altermsgtext ="pre-order";
        }
        else {
            $altermsgtext ="Payment";
        }
    
        $message = "Your $altermsgtext on Fueladream.com has been Failed towards $campaign_title.";
        $bcc = "admin@fueladream.com";
        /*** Check if campaign title empty **/
        /*
            Updated by Neha
            Updated On : 14/10/2020
            Reason : Avoid Repeating SMS and Email confirmation
        */
        if(!empty($campaign_title)){
            //preorder / contribution based on cate_id,reward existence
            $emailvar1 = 'Payment';
            
            if(isset($_SESSION['reward_id']))
            {
            if($data['project_info']['cate_id']==13 || $data['project_info']['cate_id']==12 || $data['project_info']['cate_id']==66)
            {
                $email_array['is_product']=1;
                
                $email_array['comobileno'] = $this->funder->get_shipping_contact_info($data['project_id']);
                $email_array['comobno'] = $email_array['comobileno']['shipping_contact'];
            }
            }
    
            $this->load->config('email');
            $email_template = $this->load->view('Emails/fail-transaction', $email_array, TRUE);
            $this->load->library('email');
            $this->email->clear();
            $this->email->initialize(array('mailtype' => 'html'));
            $this->email->set_newline("\r\n");
            $this->email->from("getintouch@fueladream.com", "Fueladream");
            $this->email->to($email);
            $this->email->bcc($bcc);
            $this->email->subject("Failed $emailvar1");
            $this->email->message($email_template);
            $this->email->send();
            
            $this->funder->update_payment_flag($data['payment_details']['fpi_payment_id']);
            
        }
        }
      }
}
?>