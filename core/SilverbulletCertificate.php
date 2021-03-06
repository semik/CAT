<?php

/*
 * Contributions to this work were made on behalf of the GÉANT project, a 
 * project that has received funding from the European Union’s Horizon 2020 
 * research and innovation programme under Grant Agreement No. 731122 (GN4-2).
 * 
 * On behalf of the GÉANT project, GEANT Association is the sole owner of the 
 * copyright in all material which was developed by a member of the GÉANT 
 * project. GÉANT Vereniging (Association) is registered with the Chamber of 
 * Commerce in Amsterdam with registration number 40535155 and operates in the
 * UK as a branch of GÉANT Vereniging. 
 * 
 * Registered office: Hoekenrode 3, 1102BR Amsterdam, The Netherlands. 
 * UK branch address: City House, 126-130 Hills Road, Cambridge CB2 1PQ, UK
 * 
 * License: see the web/copyright.inc.php file in the file structure or
 *          <base_url>/copyright.php after deploying the software
 */

/**
 * This file contains the SilverbulletInvitation class.
 *
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @author Tomasz Wolniewicz <twoln@umk.pl>
 *
 * @package Developer
 *
 */

namespace core;

use \Exception;
use \SoapFault;

class SilverbulletCertificate extends EntityWithDBProperties {

    public $username;
    public $expiry;
    public $serial;
    public $dbId;
    public $invitationId;
    public $userId;
    public $profileId;
    public $issued;
    public $device;
    public $revocationStatus;
    public $revocationTime;
    public $ocsp;
    public $ocspTimestamp;
    public $status;
    public $ca_type;

    const CERTSTATUS_VALID = 1;
    const CERTSTATUS_EXPIRED = 2;
    const CERTSTATUS_REVOKED = 3;
    const CERTSTATUS_INVALID = 4;

    /**
     * instantiates an existing certificate, identified either by its serial
     * number or the username. 
     * 
     * Use static issueCertificate() to generate a whole new cert.
     * 
     * @param int|string $identifier identify certificate either by CN or by serial
     * @param string     $certtype   RSA or ECDSA?
     */
    public function __construct($identifier, $certtype) {
        $this->databaseType = "INST";
        parent::__construct();
        $this->username = "";
        $this->expiry = "2000-01-01 00:00:00";
        $this->serial = -1;
        $this->dbId = -1;
        $this->invitationId = -1;
        $this->userId = -1;
        $this->profileId = -1;
        $this->issued = "2000-01-01 00:00:00";
        $this->device = NULL;
        $this->revocationStatus = "REVOKED";
        $this->revocationTime = "2000-01-01 00:00:00";
        $this->ocsp = NULL;
        $this->ocspTimestamp = "2000-01-01 00:00:00";
        $this->ca_type = $certtype;
        $this->status = SilverbulletCertificate::CERTSTATUS_INVALID;

        $incoming = FALSE;
        if (is_numeric($identifier)) {
            $incoming = $this->databaseHandle->exec("SELECT `id`, `profile_id`, `silverbullet_user_id`, `silverbullet_invitation_id`, `serial_number`, `cn` ,`expiry`, `issued`, `device`, `revocation_status`, `revocation_time`, `OCSP`, `OCSP_timestamp` FROM `silverbullet_certificate` WHERE serial_number = ? AND ca_type = ?", "is", $identifier, $certtype);
        } else { // it's a string instead
            $incoming = $this->databaseHandle->exec("SELECT `id`, `profile_id`, `silverbullet_user_id`, `silverbullet_invitation_id`, `serial_number`, `cn` ,`expiry`, `issued`, `device`, `revocation_status`, `revocation_time`, `OCSP`, `OCSP_timestamp` FROM `silverbullet_certificate` WHERE cn = ? AND ca_type = ?", "ss", $identifier, $certtype);
        }

        // SELECT -> mysqli_resource, not boolean
        while ($oneResult = mysqli_fetch_object(/** @scrutinizer ignore-type */ $incoming)) { // there is only at most one
            $this->username = $oneResult->cn;
            $this->expiry = $oneResult->expiry;
            $this->serial = $oneResult->sn;
            $this->dbId = $oneResult->id;
            $this->invitationId = $oneResult->silverbullet_invitation_id;
            $this->userId = $oneResult->silverbullet_user_id;
            $this->profileId = $oneResult->profile_id;
            $this->issued = $oneResult->issued;
            $this->device = $oneResult->device;
            $this->revocationStatus = $oneResult->revocation_status;
            $this->revocationTime = $oneResult->revocation_time;
            $this->ocsp = $oneResult->OCSP;
            $this->ocspTimestamp = $oneResult->OCSP_timestamp;
            // is the cert expired?
            $now = new \DateTime();
            $cert_expiry = new \DateTime($this->expiry);
            $delta = $now->diff($cert_expiry);
            $this->status = ($delta->invert == 1 ? SilverbulletCertificate::CERTSTATUS_EXPIRED : SilverbulletCertificate::CERTSTATUS_VALID);
            // expired is expired; even if it was previously revoked. But do update status for revoked ones...
            if ($this->status == SilverbulletCertificate::CERTSTATUS_VALID && $this->revocationStatus == "REVOKED") {
                $this->status = SilverbulletCertificate::CERTSTATUS_REVOKED;
            }
        }
    }

    /**
     * retrieve basic information about the certificate
     * 
     * @return array of basic certificate details
     */
    public function getBasicInfo() {
        $returnArray = []; // unnecessary because the iterator below is never empty, but Scrutinizer gets excited nontheless
        foreach (['status', 'serial', 'username', 'issued', 'expiry', 'ca_type'] as $key) {
            $returnArray[$key] = $this->$key;
        }
        $returnArray['device'] = \devices\Devices::listDevices()[$this->device]['display'] ?? $this->device;
        return $returnArray;
    }

    /**
     * we don't use caching in SB, so this function does nothing
     * 
     * @return void
     */
    public function updateFreshness() {
        // nothing to be done here.
    }

    /**
     * issue a certificate based on a token
     *
     * @param string $token          the token string
     * @param string $importPassword the PIN
     * @param string $certtype       is this for the RSA or ECDSA CA?
     * @return array
     */
    public static function issueCertificate($token, $importPassword, $certtype) {
        $loggerInstance = new common\Logging();
        $databaseHandle = DBConnection::handle("INST");
        $loggerInstance->debug(5, "generateCertificate() - starting.\n");
        $invitationObject = new SilverbulletInvitation($token);
        $profile = new ProfileSilverbullet($invitationObject->profile);
        $inst = new IdP($profile->institution);
        $loggerInstance->debug(5, "tokenStatus: done, got " . $invitationObject->invitationTokenStatus . ", " . $invitationObject->profile . ", " . $invitationObject->userId . ", " . $invitationObject->expiry . ", " . $invitationObject->invitationTokenString . "\n");
        if ($invitationObject->invitationTokenStatus != SilverbulletInvitation::SB_TOKENSTATUS_VALID && $invitationObject->invitationTokenStatus != SilverbulletInvitation::SB_TOKENSTATUS_PARTIALLY_REDEEMED) {
            throw new Exception("Attempt to generate a SilverBullet installer with an invalid/redeemed/expired token. The user should never have gotten that far!");
        }

        // SQL query to find the expiry date of the *user* to find the correct ValidUntil for the cert
        $user = $invitationObject->userId;
        $userrow = $databaseHandle->exec("SELECT expiry FROM silverbullet_user WHERE id = ?", "i", $user);
        // SELECT -> resource, not boolean
        if ($userrow->num_rows != 1) {
            throw new Exception("Despite a valid token, the corresponding user was not found in database or database query error!");
        }
        $expiryObject = mysqli_fetch_object(/** @scrutinizer ignore-type */ $userrow);
        $loggerInstance->debug(5, "EXP: " . $expiryObject->expiry . "\n");
        $expiryDateObject = date_create_from_format("Y-m-d H:i:s", $expiryObject->expiry);
        if ($expiryDateObject === FALSE) {
            throw new Exception("The expiry date we got from the DB is bogus!");
        }
        $loggerInstance->debug(5, $expiryDateObject->format("Y-m-d H:i:s") . "\n");
        // date_create with no parameters can't fail, i.e. is never FALSE
        $validity = date_diff(/** @scrutinizer ignore-type */ date_create(), $expiryDateObject);
        $expiryDays = $validity->days + 1;
        if ($validity->invert == 1) { // negative! That should not be possible
            throw new Exception("Attempt to generate a certificate for a user which is already expired!");
        }
        switch ($certtype) {
            case \devices\Devices::SUPPORT_RSA:
                $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'encrypt_key' => FALSE]);
                break;
            case \devices\Devices::SUPPORT_ECDSA:
                $privateKey = openssl_pkey_new(['curve_name' => 'secp384r1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'encrypt_key' => FALSE]);
                break;
            default:
                throw new Exception("Unknown certificate type!");
        }

        $csr = SilverbulletCertificate::generateCsr($privateKey, strtoupper($inst->federation), $profile->getAttributes("internal:realm")[0]['value'], $certtype);

        $loggerInstance->debug(5, "generateCertificate: proceeding to sign cert.\n");

        $certMeta = SilverbulletCertificate::signCsr($csr["CSR"], $expiryDays, $certtype);
        $cert = $certMeta["CERT"];
        $issuingCaPem = $certMeta["ISSUER"];
        $rootCaPem = $certMeta["ROOT"];
        $serial = $certMeta["SERIAL"];

        if ($cert === FALSE) {
            throw new Exception("The CA did not generate a certificate.");
        }
        $loggerInstance->debug(5, "generateCertificate: post-processing certificate.\n");

        // with the cert, our private key and import password, make a PKCS#12 container out of it
        $exportedCertProt = "";
        openssl_pkcs12_export($cert, $exportedCertProt, $privateKey, $importPassword, ['extracerts' => [$issuingCaPem /* , $rootCaPem */]]);
        // and without intermediate, to keep EAP conversation short where possible
        $exportedNoInterm = "";
        openssl_pkcs12_export($cert, $exportedNoInterm, $privateKey, $importPassword, []);
        $exportedCertClear = "";
        openssl_pkcs12_export($cert, $exportedCertClear, $privateKey, "", ['extracerts' => [$issuingCaPem, $rootCaPem]]);
        // store resulting cert CN and expiry date in separate columns into DB - do not store the cert data itself as it contains the private key!
        // we need the *real* expiry date, not just the day-approximation
        $x509 = new \core\common\X509();
        $certString = "";
        openssl_x509_export($cert, $certString);
        $parsedCert = $x509->processCertificate($certString);
        $loggerInstance->debug(5, "CERTINFO: " . print_r($parsedCert['full_details'], true));
        $realExpiryDate = date_create_from_format("U", $parsedCert['full_details']['validTo_time_t'])->format("Y-m-d H:i:s");

        // store new cert info in DB
        $databaseHandle->exec("INSERT INTO `silverbullet_certificate` (`profile_id`, `silverbullet_user_id`, `silverbullet_invitation_id`, `serial_number`, `cn` ,`expiry`, `ca_type`) VALUES (?, ?, ?, ?, ?, ?, ?)", "iiissss", $invitationObject->profile, $invitationObject->userId, $invitationObject->identifier, $serial, $csr["USERNAME"], $realExpiryDate, $certtype);
        // newborn cert immediately gets its "valid" OCSP response
        $certObject = new SilverbulletCertificate($serial, $certtype);
        $certObject->triggerNewOCSPStatement();
// return PKCS#12 data stream
        return [
            "certObject" => $certObject,
            "certdata" => $exportedCertProt,
            "certdata_nointermediate" => $exportedNoInterm,
            "certdataclear" => $exportedCertClear,
            // Scrutinizer thinks this needs to be a string, but a resource is just fine
            "sha1" => openssl_x509_fingerprint(/** @scrutinizer ignore-type */$cert, "sha1"),
            "sha256" => openssl_x509_fingerprint(/** @scrutinizer ignore-type */$cert, "sha256"),
            'importPassword' => $importPassword,
            'GUID' => common\Entity::uuid("", $exportedCertProt),
        ];
    }

    /**
     * triggers a new OCSP statement for the given serial number
     * 
     * @return string DER-encoded OCSP status info (binary data!)
     */
    public function triggerNewOCSPStatement() {
        $logHandle = new \core\common\Logging();
        $logHandle->debug(2, "Triggering new OCSP statement for serial $this->serial.\n");
        switch (CONFIG_CONFASSISTANT['SILVERBULLET']['CA']['type']) {
            case "embedded":
                $certstatus = "";
                // get all relevant info from object properties
                if ($this->serial >= 0) { // let's start with the assumption that the cert is valid
                    if ($this->revocationStatus == "REVOKED") {
                        // already revoked, simply return canned OCSP response
                        $certstatus = "R";
                    } else {
                        $certstatus = "V";
                    }
                }

                $originalExpiry = date_create_from_format("Y-m-d H:i:s", $this->expiry);
                if ($originalExpiry === FALSE) {
                    throw new Exception("Unable to calculate original expiry date, input data bogus!");
                }
                $validity = date_diff(/** @scrutinizer ignore-type */ date_create(), $originalExpiry);
                if ($validity->invert == 1) {
                    // negative! Cert is already expired, no need to revoke. 
                    // No need to return anything really, but do return the last known OCSP statement to prevent special case
                    $certstatus = "E";
                }
                $profile = new ProfileSilverbullet($this->profileId);
                $inst = new IdP($profile->institution);
                $federation = strtoupper($inst->federation);
                // generate stub index.txt file
                $tempdirArray = \core\common\Entity::createTemporaryDirectory("test");
                $tempdir = $tempdirArray['dir'];
                $nowIndexTxt = (new \DateTime())->format("ymdHis") . "Z";
                $expiryIndexTxt = $originalExpiry->format("ymdHis") . "Z";
                $serialHex = strtoupper(dechex($this->serial));
                if (strlen($serialHex) % 2 == 1) {
                    $serialHex = "0" . $serialHex;
                }

                $indexStatement = "$certstatus\t$expiryIndexTxt\t" . ($certstatus == "R" ? "$nowIndexTxt,unspecified" : "") . "\t$serialHex\tunknown\t/O=" . CONFIG_CONFASSISTANT['CONSORTIUM']['name'] . "/OU=$federation/CN=$this->username\n";
                $logHandle->debug(4, "index.txt contents-to-be: $indexStatement");
                if (!file_put_contents($tempdir . "/index.txt", $indexStatement)) {
                    $logHandle->debug(1, "Unable to write openssl index.txt file for revocation handling!");
                }
                // index.txt.attr is dull but needs to exist
                file_put_contents($tempdir . "/index.txt.attr", "unique_subject = yes\n");
                // call "openssl ocsp" to manufacture our own OCSP statement
                // adding "-rmd sha1" to the following command-line makes the
                // choice of signature algorithm for the response explicit
                // but it's only available from openssl-1.1.0 (which we do not
                // want to require just for that one thing).
                $execCmd = CONFIG['PATHS']['openssl'] . " ocsp -issuer " . ROOT . "/config/SilverbulletClientCerts/real-" . $this->ca_type . ".pem -sha1 -ndays 10 -no_nonce -serial 0x$serialHex -CA " . ROOT . "/config/SilverbulletClientCerts/real-" . $this->ca_type . ".pem -rsigner " . ROOT . "/config/SilverbulletClientCerts/real-" . $this->ca_type . ".pem -rkey " . ROOT . "/config/SilverbulletClientCerts/real-" . $this->ca_type . ".key -index $tempdir/index.txt -no_cert_verify -respout $tempdir/$serialHex.response.der";
                $logHandle->debug(2, "Calling openssl ocsp with following cmdline: $execCmd\n");
                $output = [];
                $return = 999;
                exec($execCmd, $output, $return);
                if ($return !== 0) {
                    throw new Exception("Non-zero return value from openssl ocsp!");
                }
                $ocsp = file_get_contents($tempdir . "/$serialHex.response.der");
                // remove the temp dir!
                unlink($tempdir . "/$serialHex.response.der");
                unlink($tempdir . "/index.txt.attr");
                unlink($tempdir . "/index.txt");
                rmdir($tempdir);
                break;
            case "eduPKI":
                // nothing to be done here - eduPKI have their own OCSP responder
                // and the certs point to it. So we are not in the loop.
                break;
            default:
                /* HTTP POST the serial to the CA. The CA knows about the state of
                 * the certificate.
                 *
                 * $httpResponse = httpRequest("https://clientca.hosted.eduroam.org/ocsp/", ["serial" => $serial ] );
                 *
                 * The result of this if clause has to be a DER-encoded OCSP statement
                 * to be stored in the variable $ocsp
                 */
                throw new Exception("This type of silverbullet CA is not implemented yet!");
        }
        // write the new statement into DB
        $this->databaseHandle->exec("UPDATE silverbullet_certificate SET OCSP = ?, OCSP_timestamp = NOW() WHERE serial_number = ?", "si", $ocsp, $this->serial);
        return $ocsp;
    }

    /**
     * revokes a certificate
     * 
     * @return void
     * @throws Exception
     */
    public function revokeCertificate() {
        $nowSql = (new \DateTime())->format("Y-m-d H:i:s");
        // regardless if embedded or not, always keep local state in our own DB
        $this->databaseHandle->exec("UPDATE silverbullet_certificate SET revocation_status = 'REVOKED', revocation_time = ? WHERE serial_number = ? AND ca_type = ?", "sis", $nowSql, $this->serial, $this->ca_type);
        $this->loggerInstance->debug(2, "Certificate revocation status for $this->serial updated, about to call triggerNewOCSPStatement().\n");
        // newly instantiate us, DB content has changed...
        $certObject = new SilverbulletCertificate((string) $this->serial, $this->ca_type);
        // embedded CA does "nothing special" for revocation: the DB change was the entire thing to do
        // but for external CAs, we need to notify explicitly that the cert is now revoked
        switch (CONFIG_CONFASSISTANT['SILVERBULLET']['CA']['type']) {
            case "embedded":
                $certObject->triggerNewOCSPStatement();
                break;
            case "eduPKI":
                try {
                    $soap = SilverbulletCertificate::initEduPKISoapSession("RA");
                    $soapRevocationSerial = $soap->newRevocationRequest(["Serial", $certObject->serial], "");
                    if ($soapRevocationSerial == 0) {
                        throw new Exception("Unable to create revocation request, serial number was zero.");
                    }
                    // retrieve the raw request to prepare for signature and approval
                    $soapRawRevRequest = $soap->getRawRevocationRequest($soapRevocationSerial);
                    if (strlen($soapRawRevRequest) < 10) { // very basic error handling
                        throw new Exception("Suspiciously short data to sign!");
                    }
                    // for obnoxious reasons, we have to dump the request into a file and let pkcs7_sign read from the file
                    // rather than just using the string. Grr.
                    $tempdir = \core\common\Entity::createTemporaryDirectory("test");
                    file_put_contents($tempdir['dir'] . "/content.txt", $soapRawRevRequest);
                    // retrieve our RA cert from filesystem
                    // sign the data, using cmdline because openssl_pkcs7_sign produces strange results
                    // -binary didn't help, nor switch -md to sha1 sha256 or sha512
                    $this->loggerInstance->debug(5, "Actual content to be signed is this:\n$soapRawRevRequest\n");
                    $execCmd = CONFIG['PATHS']['openssl'] . " smime -sign -binary -in " . $tempdir['dir'] . "/content.txt -out " . $tempdir['dir'] . "/signature.txt -outform pem -inkey " . ROOT . "/config/SilverbulletClientCerts/edupki-test-ra.clearkey -signer " . ROOT . "/config/SilverbulletClientCerts/edupki-test-ra.pem";
                    $this->loggerInstance->debug(2, "Calling openssl smime with following cmdline: $execCmd\n");
                    $output = [];
                    $return = 999;
                    exec($execCmd, $output, $return);
                    if ($return !== 0) {
                        throw new Exception("Non-zero return value from openssl smime!");
                    }
                    // and get the signature blob back from the filesystem
                    $detachedSig = trim(file_get_contents($tempdir['dir'] . "/signature.txt"));
                    $soapIssueRev = $soap->approveRevocationRequest($soapRevocationSerial, $soapRawRevRequest, $detachedSig);
                    if ($soapIssueRev === FALSE) {
                        throw new Exception("The locally approved revocation request was NOT processed by the CA.");
                    }
                } catch (Exception $e) {
                    // PHP 7.1 can do this much better
                    if (is_soap_fault($e)) {
                        throw new Exception("Error when sending SOAP request: " . "{$e->faultcode}: {$e->faultstring}\n");
                    }
                    throw new Exception("Something odd happened while doing the SOAP request:" . $e->getMessage());
                }
                break;
            default:
                throw new Exception("Unknown type of CA requested!");
        }
    }

    /**
     * create a CSR
     * 
     * @param resource $privateKey the private key to create the CSR with
     * @param string   $fed        the federation to which the certificate belongs
     * @param string   $realm      the realm for the future username
     * @param string   $certtype   which type of certificate to generate: RSA or ECDSA
     * @return array with the CSR and some meta info
     */
    private static function generateCsr($privateKey, $fed, $realm, $certtype) {
        $databaseHandle = DBConnection::handle("INST");
        $loggerInstance = new common\Logging();
        $usernameIsUnique = FALSE;
        $username = "";
        while ($usernameIsUnique === FALSE) {
            $usernameLocalPart = common\Entity::randomString(64 - 1 - strlen($realm), "0123456789abcdefghijklmnopqrstuvwxyz");
            $username = $usernameLocalPart . "@" . $realm;
            $uniquenessQuery = $databaseHandle->exec("SELECT cn from silverbullet_certificate WHERE cn = ?", "s", $username);
            // SELECT -> resource, not boolean
            if (mysqli_num_rows(/** @scrutinizer ignore-type */ $uniquenessQuery) == 0) {
                $usernameIsUnique = TRUE;
                }
                }

                $loggerInstance->debug(5, "generateCertificate: generating CSR.\n");

        switch (CONFIG_CONFASSISTANT['SILVERBULLET']['CA']['type']) {
            case "embedded":
        switch ($certtype) {
            case \devices\Devices::SUPPORT_RSA:
                $alg = "sha256";
                break;
            case \devices\Devices::SUPPORT_ECDSA:
                $alg = "ecdsa-with-SHA1";
                break;
            default:
                throw new Exception("Unknown certificate type!");
        }
        $newCsr = openssl_csr_new(
                ['O' => CONFIG_CONFASSISTANT['CONSORTIUM']['name'],
            'OU' => $fed,
            'CN' => $username,
            // 'emailAddress' => $username,
                ], $privateKey, [
            'digest_alg' => $alg,
            'req_extensions' => 'v3_req',
                ]
        );
                break;
            case "eduPKI":
                $tempdirArray = \core\common\Entity::createTemporaryDirectory("test");
                $tempdir = $tempdirArray['dir'];
                // dump private key into directly
                $outstring = "";
                openssl_pkey_export($privateKey, $outstring);
                file_put_contents($tempdir . "/pkey.pem", $outstring);
                // PHP can only do one DC in the Subject. But we need three.
                $execCmd = CONFIG['PATHS']['openssl'] . " req -new -sha256 -key $tempdir/pkey.pem -out $tempdir/request.csr -subj /DC=test/DC=test/DC=eduroam/C=$fed/O=" . CONFIG_CONFASSISTANT['CONSORTIUM']['name'] . "/OU=$fed/CN=$username/emailAddress=$username";
                $loggerInstance->debug(2, "Calling openssl req with following cmdline: $execCmd\n");
                $output = [];
                $return = 999;
                exec($execCmd, $output, $return);
                if ($return !== 0) {
                    throw new Exception("Non-zero return value from openssl req!");
                }
                $newCsr = file_get_contents("$tempdir/request.csr");
                // remove the temp dir!
                unlink("$tempdir/pkey.pem");
                unlink("$tempdir/request.csr");
                rmdir($tempdir);
                break;
            default:
                throw new Exception("Unknown CA!");
        }
        if ($newCsr === FALSE) {
            throw new Exception("Unable to create a CSR!");
        }
        return [
            "CSR" => $newCsr, // a resource for embedded, a string for eduPKI
            "USERNAME" => $username,
            "FED" => $fed
        ];
    }

    /**
     * a function that converts integers beyond PHP_INT_MAX to strings for
     * sending in XML messages
     *
     * taken and adapted from 
     * https://www.uni-muenster.de/WWUCA/de/howto-special-phpsoap.html
     * 
     * @param string $x the integer as an XML fragment
     * @return array the integer in array notation
     */
    public static function soapFromXmlInteger($x) {
        $y = simplexml_load_string($x);
        return array(
            $y->getName(),
            $y->__toString()
        );
    }

    /**
     * a function that converts integers beyond PHP_INT_MAX to strings for
     * sending in XML messages
     * 
     * @param array $x the integer in array notation
     * @return string the integer as string in an XML fragment
     */
    public static function soapToXmlInteger($x) {
        return '<' . $x[0] . '>'
                . htmlentities($x[1], ENT_NOQUOTES | ENT_XML1)
                . '</' . $x[0] . '>';
    }

    /**
     * sets up a connection to the eduPKI SOAP interfaces
     * There is a public interface and an RA-restricted interface;
     * the latter needs an RA client certificate to identify the operator
     * 
     * @param string $type to which interface should we connect to - "PUBLIC" or "RA"
     * @return \SoapClient the connection object
     * @throws Exception
     */
    private static function initEduPKISoapSession($type) {
        // set context parameters common to both endpoints
        $context_params = [
            'http' => [
                'timeout' => 60,
                'user_agent' => 'Stefan',
                'protocol_version' => 1.1
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                // below is the CA "/C=DE/O=Deutsche Telekom AG/OU=T-TeleSec Trust Center/CN=Deutsche Telekom Root CA 2"
                'cafile' => ROOT . "/config/SilverbulletClientCerts/eduPKI-webserver-root.pem",
                'verify_depth' => 5,
                'capture_peer_cert' => true,
            ],
        ];
        $url = "";
        switch ($type) {
            case "PUBLIC":
                $url = "https://pki.edupki.org/edupki-test-ca/cgi-bin/pub/soap?wsdl=1";
                $context_params['ssl']['peer_name'] = 'pki.edupki.org';
                break;
            case "RA":
                $url = "https://ra.edupki.org/edupki-test-ca/cgi-bin/ra/soap?wsdl=1";
                $context_params['ssl']['peer_name'] = 'ra.edupki.org';
                break;
            default:
                throw new Exception("Unknown type of eduPKI interface requested.");
        }
        if ($type == "RA") { // add client auth parameters to the context
            $context_params['ssl']['local_cert'] = ROOT . "/config/SilverbulletClientCerts/edupki-test-ra.pem";
            $context_params['ssl']['local_pk'] = ROOT . "/config/SilverbulletClientCerts/edupki-test-ra.clearkey";
            // $context_params['ssl']['passphrase'] = SilverbulletCertificate::EDUPKI_RA_PKEY_PASSPHRASE;
        }
        // initialse connection to eduPKI CA / eduroam RA
        $soap = new \SoapClient($url, [
            'soap_version' => SOAP_1_1,
            'trace' => TRUE,
            'exceptions' => TRUE,
            'connection_timeout' => 5, // if can't establish the connection within 5 sec, something's wrong
            'cache_wsdl' => WSDL_CACHE_NONE,
            'user_agent' => 'eduroam CAT to eduPKI SOAP Interface',
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            'stream_context' => stream_context_create($context_params),
            'typemap' => [
                [
                    'type_ns' => 'http://www.w3.org/2001/XMLSchema',
                    'type_name' => 'integer',
                    'from_xml' => 'core\SilverbulletCertificate::soapFromXmlInteger',
                    'to_xml' => 'core\SilverbulletCertificate::soapToXmlInteger',
                ],
            ],
                ]
        );
        return $soap;
    }

    const EDUPKI_RA_ID = 700;
    const EDUPKI_CERT_PROFILE = "User SOAP";
    const EDUPKI_RA_PKEY_PASSPHRASE = "...";

    /**
     * take a CSR and sign it with our issuing CA's certificate
     * 
     * @param mixed  $csr        the CSR
     * @param int    $expiryDays the number of days until the cert is going to expire
     * @param string $certtype   which type of certificate to use for signing
     * @return array the cert and some meta info
     */
    private static function signCsr($csr, $expiryDays, $certtype) {
        $loggerInstance = new common\Logging();
        $databaseHandle = DBConnection::handle("INST");
        switch (CONFIG_CONFASSISTANT['SILVERBULLET']['CA']['type']) {
            case "embedded":
                $rootCaPem = file_get_contents(ROOT . "/config/SilverbulletClientCerts/rootca-$certtype.pem");
                $issuingCaPem = file_get_contents(ROOT . "/config/SilverbulletClientCerts/real-$certtype.pem");
                $issuingCa = openssl_x509_read($issuingCaPem);
                $issuingCaKey = openssl_pkey_get_private("file://" . ROOT . "/config/SilverbulletClientCerts/real-$certtype.key");
                $nonDupSerialFound = FALSE;
                do {
                    $serial = random_int(1000000000, PHP_INT_MAX);
                    $dupeQuery = $databaseHandle->exec("SELECT serial_number FROM silverbullet_certificate WHERE serial_number = ? AND ca_type = ?", "is", $serial, $certtype);
                    // SELECT -> resource, not boolean
                    if (mysqli_num_rows(/** @scrutinizer ignore-type */$dupeQuery) == 0) {
                        $nonDupSerialFound = TRUE;
                    }
                } while (!$nonDupSerialFound);
                $loggerInstance->debug(5, "generateCertificate: signing imminent with unique serial $serial, cert type $certtype.\n");
                switch ($certtype) {
                    case \devices\Devices::SUPPORT_RSA:
                        $alg = "sha256";
                        break;
                    case \devices\Devices::SUPPORT_ECDSA:
                        $alg = "ecdsa-with-SHA1";
                        break;
                    default:
                        throw new Exception("Unknown cert type!");
                }
                return [
                    "CERT" => openssl_csr_sign($csr, $issuingCa, $issuingCaKey, $expiryDays, ['digest_alg' => $alg, 'config' => dirname(__DIR__) . "/config/SilverbulletClientCerts/openssl-$certtype.cnf"], $serial),
                    "SERIAL" => $serial,
                    "ISSUER" => $raCertFile,
                    "ROOT" => $rootCaPem,
                ];
            case "eduPKI":
                // initialse connection to eduPKI CA / eduroam RA and send the request to them
                try {
                    $altArray = [# Array mit den Subject Alternative Names
                        "email:" . $csr["USERNAME"]
                    ];
                    $soapPub = SilverbulletCertificate::initEduPKISoapSession("PUBLIC");
                    $loggerInstance->debug(5, "FIRST ACTUAL SOAP REQUEST (Public, newRequest)!\n");
                    $loggerInstance->debug(5, "PARAM_1: " . SilverbulletCertificate::EDUPKI_RA_ID . "\n");
                    $loggerInstance->debug(5, "PARAM_2: " . $csr["CSR"] . "\n");
                    $loggerInstance->debug(5, "PARAM_3: ");
                    $loggerInstance->debug(5, $altArray);
                    $loggerInstance->debug(5, "PARAM_4: " . SilverbulletCertificate::EDUPKI_CERT_PROFILE . "\n");
                    $loggerInstance->debug(5, "PARAM_5: " . sha1("notused") . "\n");
                    $loggerInstance->debug(5, "PARAM_6: " . $csr["USERNAME"] . "\n");
                    $loggerInstance->debug(5, "PARAM_7: " . $csr["USERNAME"] . "\n");
                    $loggerInstance->debug(5, "PARAM_8: " . ProfileSilverbullet::PRODUCTNAME . "\n");
                    $loggerInstance->debug(5, "PARAM_9: false\n");
                    $soapNewRequest = $soapPub->newRequest(
                            SilverbulletCertificate::EDUPKI_RA_ID, # RA-ID
                            $csr["CSR"], # Request im PEM-Format
                            $altArray, # altNames
                            SilverbulletCertificate::EDUPKI_CERT_PROFILE, # Zertifikatprofil
                            sha1("notused"), # PIN
                            $csr["USERNAME"], # Name des Antragstellers
                            $csr["USERNAME"], # Kontakt-E-Mail
                            ProfileSilverbullet::PRODUCTNAME, # Organisationseinheit des Antragstellers
                            false                   # Veröffentlichen des Zertifikats?
                    );
                    $loggerInstance->debug(5, $soapPub->__getLastRequest());
                    $loggerInstance->debug(5, $soapPub->__getLastResponse());
                    if ($soapNewRequest == 0) {
                        throw new Exception("Error when sending SOAP request (request serial number was zero). No further details available.");
                    }
                    $soapReqnum = intval($soapNewRequest);
                } catch (Exception $e) {
                    // PHP 7.1 can do this much better
                    if (is_soap_fault($e)) {
                        throw new Exception("Error when sending SOAP request: " . "{$e->faultcode}:  {
                    $e->faultstring
                }\n");
                    }
                    throw new Exception("Something odd happened while doing the SOAP request:" . $e->getMessage());
                }
                try {
                    $soap = SilverbulletCertificate::initEduPKISoapSession("RA");
                    // tell the CA the desired expiry date of the new certificate
                    $expiry = new \DateTime();
                    $expiry->modify("+$expiryDays day");
                    $expiry->setTimezone(new \DateTimeZone("UTC"));
                    $soapExpiryChange = $soap->setRequestParameters(
                            $soapReqnum, [
                        "RaID" => SilverbulletCertificate::EDUPKI_RA_ID,
                        "Role" => SilverbulletCertificate::EDUPKI_CERT_PROFILE,
                        "Subject" => "DC=eduroam,DC=test,DC=test,C=" . $csr["FED"] . ",O=" . CONFIG_CONFASSISTANT['CONSORTIUM']['name'] . ",OU=" . $csr["FED"] . ",CN=" . $csr['USERNAME'] . ",emailAddress=" . $csr['USERNAME'],
                        "SubjectAltNames" => ["email:" . $csr["USERNAME"]],
                        "NotBefore" => (new \DateTime())->format('c'),
                        "NotAfter" => $expiry->format('c'),
                            ]
                    );
                    if ($soapExpiryChange === FALSE) {
                        throw new Exception("Error when sending SOAP request (unable to change expiry date).");
                    }
                    // retrieve the raw request to prepare for signature and approval
                    // this seems to come out base64-decoded already; maybe PHP
                    // considers this "convenience"? But we need it as sent on
                    // the wire, so re-encode it!
                    $soapCleartext = $soap->getRawRequest($soapReqnum);

                    $loggerInstance->debug(5, "Actual received SOAP resonse for getRawRequest was:\n\n");
                    $loggerInstance->debug(5, $soap->__getLastResponse());
                    // for obnoxious reasons, we have to dump the request into a file and let pkcs7_sign read from the file
                    // rather than just using the string. Grr.
                    $tempdir = \core\common\Entity::createTemporaryDirectory("test");
                    file_put_contents($tempdir['dir'] . "/content.txt", $soapCleartext);
                    // retrieve our RA cert from filesystem                    
                    // the RA certificates are not needed right now because we
                    // have resorted to S/MIME signatures with openssl command-line
                    // rather than the built-in functions. But that may change in
                    // the future, so let's park these two lines for future use.
                    // $raCertFile = file_get_contents(ROOT . "/config/SilverbulletClientCerts/edupki-test-ra.pem");
                    // $raCert = openssl_x509_read($raCertFile);
                    // $raKey = openssl_pkey_get_private("file://" . ROOT . "/config/SilverbulletClientCerts/edupki-test-ra.clearkey");
                   
                    // sign the data, using cmdline because openssl_pkcs7_sign produces strange results
                    // -binary didn't help, nor switch -md to sha1 sha256 or sha512
                    $loggerInstance->debug(5, "Actual content to be signed is this:\n  $soapCleartext\n");
                    $execCmd = CONFIG['PATHS']['openssl'] . " smime -sign -binary -in " . $tempdir['dir'] . "/content.txt -out " . $tempdir['dir'] . "/signature.txt -outform pem -inkey " . ROOT . "/config/SilverbulletClientCerts/edupki-test-ra.clearkey -signer " . ROOT . "/config/SilverbulletClientCerts/edupki-test-ra.pem";
                    $loggerInstance->debug(2, "Calling openssl smime with following cmdline:   $execCmd\n");
                    $output = [];
                    $return = 999;
                    exec($execCmd, $output, $return);
                    if ($return !== 0) {
                        throw new Exception("Non-zero return value from openssl smime!");
                    }
                    // and get the signature blob back from the filesystem
                    $detachedSig = trim(file_get_contents($tempdir['dir'] . "/signature.txt"));    
                    $loggerInstance->debug(5, "Request for server approveRequest has parameters:\n");  
                    $loggerInstance->debug(5,   $soapReqnum . "\n");
                    $loggerInstance->debug(5,   $soapCleartext . "\n"); // PHP magically encodes this as base64 while sending!
                    $loggerInstance->debug(5,   $detachedSig . "\n");
                    $soapIssueCert = $soap->approveRequest($soapReqnum  , $soapCleartext  , $detachedSig);
                    $loggerInstance->debug(5, "approveRequest Request was: \n" .   $soap->__getLastRequest());
                    $loggerInstance->debug(5, "approveRequest Response was: \n" .   $soap->__getLastResponse());
                    if ($soapIssueCert === FALSE) {
                        throw new Exception("The locally approved request was NOT processed by the CA.");
                    }
                    // now, get the actual cert from the CA
                    sleep(55);
                    $counter = 55;
                    $parsedCert = NULL;
                    do {
                        $counter += 5;
                        sleep(5); // always start with a wait. Signature round-trip on the server side is at least one minute.
                        $soapCert = $soap->getCertificateByRequestSerial($soapReqnum);
                        $x509 = new common\X509();
                        if (strlen($soapCert) > 10) {
                            $parsedCert = $x509->processCertificate($soapCert);
                        }
                    } while (!is_array($parsedCert) && $counter < 500);

                    if (!is_array($parsedCert)) {
                        throw new Exception("We did not actually get a certificate after waiting for 5 minutes.");
                    }
                    // let's get the CA certificate chain

                    $caInfo = $soap->getCAInfo();
                    $certList = $x509->splitCertificate($caInfo->CAChain[0]);
                    // find the root
                    $theRoot = "";
                    foreach ($certList as   $oneCert) {
                        $content = $x509->processCertificate($oneCert);
                        if ($content['root'] == 1) {
                            $theRoot = $content;
                        }
                    }
                    if ($theRoot == "") {
                        throw new Exception("CAInfo has no root certificate for us!");
                    }
                } catch (SoapFault $e) {
                    throw new Exception("SoapFault: Error when sending or receiving SOAP message: " . "{$e->faultcode}: {$e->faultname}: {$e->faultstring}: {$e->faultactor}: {$e->detail}: {$e->headerfault}\n");
                } catch (Exception $e) {
                    throw new Exception("Exception: Something odd happened between the SOAP requests:" . $e->getMessage());
                }
                return [
                    "CERT" => openssl_x509_read($parsedCert['pem']),
                    "SERIAL" => $parsedCert['full_details']['serialNumber'],
                    "ISSUER" => $theRoot, // change this to the actual eduPKI Issuer CA
                    "ROOT" => $theRoot, // change this to the actual eduPKI Root CA
                ];
            default:
                /* HTTP POST the CSR to the CA with the $expiryDays as parameter
                 * on successful execution, gets back a PEM file which is the
                 * certificate (structure TBD)
                 * $httpResponse = httpRequest("https://clientca.hosted.eduroam.org/issue/", ["csr" => $csr, "expiry" => $expiryDays ] );
                 *
                 * The result of this if clause has to be a certificate in PHP's 
                 * "openssl_object" style (like the one that openssl_csr_sign would 
                 * produce), to be stored in the variable $cert; we also need the
                 * serial - which can be extracted from the received cert and has
                 * to be stored in $serial.
                 */
                throw new Exception("External silverbullet CA is not implemented yet!");
        }
    }

}
