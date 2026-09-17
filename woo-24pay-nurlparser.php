<?php

require_once( PLUGIN_PATH_24PAY . 'woo-24pay-signgenerator.php');

if(!class_exists('WOO_24pay_NurlParser'))
{
  class WOO_24pay_NurlParser{

    public $parsed;
    public $result;
    public $msTxnId;
    public $pspTxnId;

    private $mid;
    private $key;
    private $zip;
    private $sign;
    private $city;
    private $email;
    private $phone;
    private $given;
    private $family;
    private $street;
    private $amount;
    private $country;
    private $currency;
    private $timestamp;
    private $creditCard;
    private $pspCategory;
    
  	function __construct($income, $mid, $key)
    {
    	$this->mid = $mid;
    	$this->key = $key;
        
    	try{
            $income = trim(stripslashes(preg_replace("/^\s*<\?xml.*?\?>/i", "", $income)));
	    	$transaction = new SimpleXMLElement($income); 
	    
	        $this->sign =  (string) $transaction['sign'];
	        $this->msTxnId =  (string) $transaction->Transaction->Identification->MsTxnId;
	        $this->pspTxnId =  (string) $transaction->Transaction->Identification->PspTxnId;
	        $this->amount =  (string) $transaction->Transaction->Presentation->Amount;
	        $this->currency =  (string) $transaction->Transaction->Presentation->Currency;
	        $this->email =  (string) $transaction->Transaction->Customer->Contact->Email;
	        $this->phone =  (string) $transaction->Transaction->Customer->Contact->Phone;
	        $this->street =  (string) $transaction->Transaction->Customer->Address->Street;
	        $this->zip =  (string) $transaction->Transaction->Customer->Address->Zip;
	        $this->city = (string) $transaction->Transaction->Customer->Address->City;
	        $this->country =  (string) $transaction->Transaction->Customer->Address->Country;
	        $this->given =  (string) $transaction->Transaction->Customer->Name->Given;
	        $this->family =  (string) $transaction->Transaction->Customer->Name->Family;
	        $this->timestamp =  (string) $transaction->Transaction->Processing->Timestamp;
	        $this->result =  (string) $transaction->Transaction->Processing->Result;
	        $this->pspCategory =  (string) $transaction->Transaction->Processing->PSPCategory;
	        $this->creditCard =  (string) $transaction->Transaction->Processing->CreditCard;

	        $this->parsed = true;
    	}
        catch(\Exception $e){
			$this->parsed = false;
		}
    }
  

  	public function validateSign(){
  		if ($this->parsed){
	        $signGenerator = new WOO_24pay_SignGenerator(array('Mid'=>$this->mid), $this->key);
	        
	        $signCandidat = $signGenerator->sign($this->buildMessage());
	        if ($signCandidat==$this->sign)
	            return true;
	        else
	            return false;
        }
        return false;
    }

    private function buildMessage(){
        return $this->mid.$this->amount.$this->currency.$this->pspTxnId.$this->msTxnId.$this->timestamp.$this->result;
    }
  }
}