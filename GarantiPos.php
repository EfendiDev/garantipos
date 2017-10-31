<?php
/**
 * Created by PhpStorm.
 * User: bsevgin
 * Date: 31.10.2017
 * Time: 13:54
 */

class GarantiPos
{
    private $debugMode          = false;
    private $version            = "v0.01";
    private $mode               = "PROD"; //Test ortamı "TEST", gerçek ortam için "PROD"
    private $provUserID         = "PROVAUT"; //TerminalProvout UserID
    private $terminalID         = "XXX"; //Terminal numarası
    private $terminalID_        = "";
    private $terminalMerchantID = "XXX"; //Üye işyeri numarası
    private $storeKey           = "XXX"; //3D secure şifreniz
    private $provUserPassword   = "XXX"; //TerminalProvUserID şifresi
    private $paymentUrl         = "https://sanalposprov.garanti.com.tr/servlet/gt3dengine";
    private $paymentUrlForDebug = "https://eticaret.garanti.com.tr/destek/postback.aspx";
    private $provisionUrl       = "https://sanalposprov.garanti.com.tr/VPServlet"; //Provizyon için xml'in post edileceği adres
    public  $successUrl         = "?action=success"; //Ödeme işlemi başarılı olduğunda yönlenecek sayfa
    public  $errorUrl           = "?action=error"; //Ödeme başarısız olduğunda yönlecenek sayfa

    private $paymentRefreshTime               = "0"; //Ödeme alındıktan bekletilecek süre
    private $timeOutPeriod                    = "60";
    private $addCampaignInstallment           = "N";
    private $totalInstallamentCount           = "0";
    private $installmentOnlyForCommercialCard = "N";

    public $companyName;
    public $orderNo;
    public $amount;
    public $installmentCount;
    public $currencyCode;
    public $customerIP;
    public $customerEmail;
    public $cardName;
    public $cardNumber;
    public $cardExpiredMonth;
    public $cardExpiredYear;
    public $cardCVV;
    public $orderAddress;

    /**
     * Ödeme işlemleri için gerekli sipariş ve ödeme bilgileri setleniyor
     *
     * @param $params
     */
    public function __construct($params)
    {
        $this->terminalID_ = "0".$this->terminalID; //Başına 0 eklenerek 9 digite tamamlanmalıdır.

        $this->companyName      = $params['companyName'];
        $this->orderNo          = $params['orderNo']; //Her işlemde farklı bir değer gönderilmeli
        $this->amount           = $params['amount']*100; //İşlem Tutarı 1.00 TL için 100 gönderilmeli
        $this->installmentCount = $params['installmentCount']; //Taksit sayısı. Boş gönderilirse taksit yapılmaz
        $this->currencyCode     = "949"; //Default döviz kodu; TRY = 949
        $this->customerIP       = $params['customerIP'];
        $this->customerEmail    = $params['customerEmail'];
        $this->cardName         = $params['cardName'];
        $this->cardNumber       = $params['cardNumber'];
        $this->cardExpiredMonth = $params['cardExpiredMonth'];
        $this->cardExpiredYear  = $params['cardExpiredYear'];
        $this->cardCVV          = $params['cardCvv'];

        //Fatura bilgileri de gönderilmek istendiğinde
        if(!empty($params['orderAddress'])){
            $this->orderAddress = $params['orderAddress'];
        }
    }

    /**
     * Kredi kartı ile ödeme için buraya istek yapılacak
     */
    public function pay($type = "creditcard")
    {
        if($type=="garantipay"){
            $params = [
                "secure3dsecuritylevel" => "3D",
                "txntype"               => "sales",
                "cardname"              => $this->cardName,
                "cardnumber"            => $this->cardNumber,
                "cardexpiredatemonth"   => $this->cardExpiredMonth,
                "cardexpiredateyear"    => $this->cardExpiredYear,
                "cardcvv2"              => $this->cardCVV,
            ];
        }
        elseif($type=="garantipay"){
            $this->provUserID = "PROVOOS";
            $params           = [
                "secure3dsecuritylevel" => "CUSTOM_PAY",
                "txntype"               => "gpdatarequest",
                "txnsubtype"            => "sales",
                "lang"                  => "tr",
                "garantipay"            => "Y",
                "bnsuseflag"            => "Y", //Bonus kullanımı Y/N
                "fbbuseflag"            => "Y", //Fbb kullanımı Y/N
                "chequeuseflag"         => "Y", //Y/N
                "mileuseflag"           => "Y", //Y/N
            ];
        }

        $this->redirect_for_payment($params);
    }
    
    /**
     * Bankadan dönen cevap success ise burası çağrılacak
     *
     * @param string $type
     *
     * @return bool|mixed
     */
    public function callback($type = "creditcard")
    {
        if($type=="creditcard"){
            return $this->creditcardpay_callback();
        }
        elseif($type=="garantipay"){
            return $this->garantipay_callback();
        }
    }

    /**
     * Kredi kartı ile ödemede success durumunda burası çağrılacak
     *
     * @return bool|mixed
     */
    private function creditcardpay_callback()
    {
        $post = $_POST;

        //Hata kodları ve mesajları
        $strMDStatuses = [
            0 => "Doğrulama Başarısız, 3-D Secure imzası geçersiz",
            1 => "Tam Doğrulama",
            2 => "Kart Sahibi veya bankası sisteme kayıtlı değil",
            3 => "Kartın bankası sisteme kayıtlı değil",
            4 => "Doğrulama denemesi, kart sahibi sisteme daha sonra kayıt olmayı seçmiş",
            5 => "Doğrulama yapılamıyor",
            7 => "Sistem Hatası",
            8 => "Bilinmeyen Kart No"
        ];
        $strMDStatus   = isset($strMDStatuses[$post["mdstatus"]]) ? $post["mdstatus"] : 7;
        if($strMDStatus!=1)
            return $strMDStatuses[$strMDStatus];

        if($post['action']=="success"){
            //Tam Doğrulama, Kart Sahibi veya bankası sisteme kayıtlı değil, Kartın bankası sisteme kayıtlı değil, Doğrulama denemesi, kart sahibi sisteme daha sonra kayıt olmayı seçmiş responselarını alan işlemler için Provizyon almaya çalışıyoruz
            if($strMDStatus=="1" || $strMDStatus=="2" || $strMDStatus=="3" || $strMDStatus=="4"){
                $strNumber                = ""; //Kart bilgilerinin boş gitmesi gerekiyor
                $strExpireDate            = ""; //Kart bilgilerinin boş gitmesi gerekiyor
                $strCVV2                  = ""; //Kart bilgilerinin boş gitmesi gerekiyor
                $strCardholderPresentCode = "13"; //3D Model işlemde bu değer 13 olmalı
                $strType                  = $post["txntype"];
                $strMotoInd               = "N";
                $strAuthenticationCode    = $post["cavv"];
                $strSecurityLevel         = $post["eci"];
                $strTxnID                 = $post["xid"];
                $strMD                    = $post["md"];
                $SecurityData             = strtoupper(sha1($this->provUserPassword.$this->terminalID_));
                $HashData                 = strtoupper(sha1($this->orderNo.$this->terminalID.$this->amount.$SecurityData)); //Daha kısıtlı bilgileri HASH ediyoruz.

                //Provizyona Post edilecek XML Şablonu
                $strXML = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
                    <GVPSRequest>
                        <Mode>{$this->mode}</Mode>
                        <Version>{$this->version}</Version>
                        <ChannelCode></ChannelCode>
                        <Terminal>
                            <ProvUserID>{$this->provUserID}</ProvUserID>
                            <HashData>$HashData</HashData>
                            <UserID>{$this->terminalMerchantID}</UserID>
                            <ID>{$this->terminalID}</ID>
                            <MerchantID>{$this->terminalMerchantID}</MerchantID>
                        </Terminal>
                        <Customer>
                            <IPAddress>{$this->customerIP}</IPAddress>
                            <EmailAddress>{$this->customerEmail}</EmailAddress>
                        </Customer>
                        <Card>
                            <Number>$strNumber</Number>
                            <ExpireDate>$strExpireDate</ExpireDate>
                            <CVV2>$strCVV2</CVV2>
                        </Card>
                        <Order>
                            <OrderID>{$this->orderNo}</OrderID>
                            <GroupID></GroupID>
                            <AddressList>
                                <Address>
                                    <Type>B</Type>
                                    <Name></Name>
                                    <LastName></LastName>
                                    <Company></Company>
                                    <Text></Text>
                                    <District></District>
                                    <City></City>
                                    <PostalCode></PostalCode>
                                    <Country></Country>
                                    <PhoneNumber></PhoneNumber>
                                </Address>
                            </AddressList>
                        </Order>
                        <Transaction>
                            <Type>$strType</Type>
                            <InstallmentCnt>{$this->installmentCount}</InstallmentCnt>
                            <Amount>{$this->amount}</Amount>
                            <CurrencyCode>{$this->currencyCode}</CurrencyCode>
                            <CardholderPresentCode>$strCardholderPresentCode</CardholderPresentCode>
                            <MotoInd>$strMotoInd</MotoInd>
                            <Secure3D>
                                <AuthenticationCode>$strAuthenticationCode</AuthenticationCode>
                                <SecurityLevel>$strSecurityLevel</SecurityLevel>
                                <TxnID>$strTxnID</TxnID>
                                <Md>$strMD</Md>
                            </Secure3D>
                        </Transaction>
                    </GVPSRequest>";

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $this->provisionUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, "data=".$strXML);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                $results = curl_exec($ch);
                curl_close($ch);

                $resultXML       = simplexml_load_string($results);
                $responseCode    = $resultXML->Transaction->Response->Code;
                $responseMessage = $resultXML->Transaction->Response->Message;
                if($responseCode=="00" || $responseMessage=="Approved"){
                    return true; //Ödeme başarıyla alındı
                }
                else{
                    if($this->debugMode){
                        return '<pre>'.var_export($post,true).'</pre><br><pre>'.var_export($results,true).'</pre>';
                    }

                    return $resultXML->Transaction->Response->ErrorMsg; //Hata mesajı gönderiliyor
                }
            }
        }

        return var_export($post, true);
    }

    /**
     * GarantiPAY ile ödemede success durumunda burası çağrılacak
     *
     * @return bool
     */
    private function garantipay_callback()
    {
        $post = $_POST;

        //GarantiPay için dönen cevabın bankadan geldiği doğrulanıyor
        $responseHashparams = $post["hashparams"];
        $responseHash       = $post["hash"];
        $isValidHash        = false;
        if($responseHashparams!==null && $responseHashparams!==""){
            $digestData = "";
            $paramList  = explode(":", $responseHashparams);
            foreach($paramList as $param){
                if(isset($post[strtolower($param)])){
                    $value = $post[strtolower($param)];
                    if($value==null){
                        $value = "";
                    }
                    $digestData .= $value;
                }
            }

            $digestData     .= $this->storeKey;
            $hashCalculated = base64_encode(pack('H*', sha1($digestData)));
            if($responseHash==$hashCalculated){
                $isValidHash = true;
            }
        }

        if($isValidHash){
            return true; //Ödeme başarıyla alındı
        }
        else{
            if($this->debugMode){
                return '<pre>'.var_export($post,true).'</pre>';
            }

            return $post['errmsg']; //Hata mesajı gönderiliyor
        }
    }

    /**
     * Ödeme için banka ekranına yönlendirme işlemi yapılıyor
     *
     * @param $params
     */
    private function redirect_for_payment($params)
    {
        $params['companyname']                      = $this->companyName;
        $params['version']                          = $this->version;
        $params['mode']                             = $this->mode;
        $params['successurl']                       = $this->successUrl;
        $params['errorurl']                         = $this->errorUrl;
        $params['terminalid']                       = $this->terminalID;
        $params['terminaluserid']                   = $this->terminalID;
        $params['terminalmerchantid']               = $this->terminalMerchantID;
        $params['orderid']                          = $this->orderNo;
        $params['txnamount']                        = $this->amount;
        $params['txncurrencycode']                  = $this->currencyCode;
        $params['txninstallmentcount']              = $this->installmentCount;
        $params['txntimestamp']                     = time();
        $params['customeremailaddress']             = $this->customerEmail;
        $params['customeripaddress']                = $this->customerIP;
        $params['refreshtime']                      = $this->paymentRefreshTime;
        $params['txntimeoutperiod']                 = $this->timeOutPeriod;
        $params['addcampaigninstallment']           = $this->addCampaignInstallment;
        $params['totallinstallmentcount']           = $this->totalInstallamentCount;
        $params['installmentonlyforcommercialcard'] = $this->installmentOnlyForCommercialCard;

        $SecurityData           = strtoupper(sha1($this->provUserPassword.$this->terminalID_));
        $HashData               = strtoupper(sha1($this->terminalID.$params['orderid'].$params['txnamount'].$params['successurl'].$params['errorurl'].$params['txntype'].$params['txninstallmentcount'].$this->storeKey.$SecurityData));
        $params['secure3dhash'] = $HashData;

        /* @todo: sipariş adresleri yönlendirme formuna eklenecek
         * Siparişe yönelik Fatura bilgilerini göndermek için ekteki opsiyonel alanlar kullanılabilir.
         * Eğer birden çok Fatura detayı gönderilecekse orderaddresscount=2 yapılarak
         * Tüm element isimlerindeki 1 rakamı 2 yapılmalıdır. Örn; orderaddresscity2 gibi...*/
        //<input type="hidden" name="orderaddresscount" value="1" />
        //<input type="hidden" name="orderaddresscity1" value="xxx" />
        //<input type="hidden" name="orderaddresscompany1" value="xxx" />
        //<input type="hidden" name="orderaddresscountry1" value="xxx" />
        //<input type="hidden" name="orderaddressdistrict1" value="xxx" />
        //<input type="hidden" name="orderaddressfaxnumber1" value="xxx" />
        //<input type="hidden" name="orderaddressgsmnumber1" value="xxx" />
        //<input type="hidden" name="orderaddresslastname1" value="xxx" />
        //<input type="hidden" name="orderaddressname1" value="xxx" />
        //<input type="hidden" name="orderaddressphonenumber1" value="xxx" />
        //<input type="hidden" name="orderaddresspostalcode1" value="xxx" />
        //<input type="hidden" name="orderaddresstext1" value="xxx" />
        //<input type="hidden" name="orderaddresstype1" value="xxx" />

        print('<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">');
        print('<html>');
        print('<body>');
        print('<form action="'.($this->debugMode?$this->paymentUrl:$this->paymentUrlForDebug).'" method="post" id="three_d_form"/>');
        foreach($params as $name => $param){
            print('<input type="hidden" name="'.$name.'" value="'.$param.'"/>');
        }
        print('<input type="submit" value="Öde" style="display:none;"/>');
        print('<noscript>');
        print('<br/>');
        print('<div style="text-align:center;">');
        print('<h1>3D Secure Yönlendirme İşlemi</h1>');
        print('<h2>Javascript internet tarayıcınızda kapatılmış veya desteklenmiyor.<br/></h2>');
        print('<h3>Lütfen banka 3D Secure sayfasına yönlenmek için tıklayınız.</h3>');
        print('<input type="submit" value="3D Secure Sayfasına Yönlen">');
        print('</div>');
        print('</noscript>');
        print('</form>');
        print('</body>');
        print('<script>document.getElementById("three_d_form").submit();</script>');
        print('</html>');
        die();
    }

}