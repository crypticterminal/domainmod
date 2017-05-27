<?php
/**
 * /classes/DomainMOD/DreamHost.php
 *
 * This file is part of DomainMOD, an open source domain and internet asset manager.
 * Copyright (c) 2010-2017 Greg Chetcuti <greg@chetcuti.com>
 *
 * Project: http://domainmod.org   Author: http://chetcuti.com
 *
 * DomainMOD is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * DomainMOD is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with DomainMOD. If not, see
 * http://www.gnu.org/licenses/.
 *
 */
//@formatter:off
namespace DomainMOD;

class DreamHost
{
    private $registrar;

    public function __construct()
    {
        $this->registrar = 'DreamHost';
        $this->log = new Log('dreamhost.class');
        $this->format = new Format();
        $this->system = new System();
    }

    public function getApiUrl($api_key, $command)
    {
        $base_url = 'https://api.dreamhost.com/?key=' . $api_key;
        if ($command == 'domainlist') {
            return $base_url . '&cmd=domain-list_registrations&format=json';
        } else {
            return 'Unable to build API URL';
        }
    }

    public function apiCall($full_url)
    {
        $handle = curl_init($full_url);
        curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
        $result = curl_exec($handle);
        curl_close($handle);
        return $result;
    }

    public function getDomainList($api_key, $account_id)
    {
        $domain_list = array();
        $domain_count = 0;

        $api_url = $this->getApiUrl($api_key, 'domainlist');
        $api_results = $this->apiCall($api_url);
        $array_results = $this->convertToArray($api_results);

        // confirm that the api call was successful
        if ($array_results['result'] == 'success') {

            $tmpq = $this->system->db()->prepare("
                INSERT INTO domain_queue_temp
                (account_id, domain, expiry_date, ns1, ns2, ns3, ns4, ns5, ns6, ns7, ns8, ns9, ns10, autorenew, privacy)
                VALUES
                (:account_id, :domain, :expiry_date, :ns1, :ns2, :ns3, :ns4, :ns5, :ns6, :ns7, :ns8, :ns9, :ns10, :autorenew, :privacy)");

            foreach ($array_results['data'] as $value) {

                $domain_list[] = $value['domain'];
                $expiry_date = $this->processExpiry($value['expires']);
                if ($value['ns1'] != '' && $value['ns1'] != 'unknown') $ns1 = $value['ns1'];
                if ($value['ns2'] != '' && $value['ns2'] != 'unknown') $ns2 = $value['ns2'];
                if ($value['ns3'] != '' && $value['ns3'] != 'unknown') $ns3 = $value['ns3'];
                if ($value['ns4'] != '' && $value['ns4'] != 'unknown') $ns4 = $value['ns4'];
                if ($value['ns5'] != '' && $value['ns5'] != 'unknown') $ns5 = $value['ns5'];
                if ($value['ns6'] != '' && $value['ns6'] != 'unknown') $ns6 = $value['ns6'];
                if ($value['ns7'] != '' && $value['ns7'] != 'unknown') $ns7 = $value['ns7'];
                if ($value['ns8'] != '' && $value['ns8'] != 'unknown') $ns8 = $value['ns8'];
                if ($value['ns9'] != '' && $value['ns9'] != 'unknown') $ns9 = $value['ns9'];
                if ($value['ns10'] != '' && $value['ns10'] != 'unknown') $ns10 = $value['ns10'];
                $autorenew = $this->processAutorenew($value['autorenew']);
                $privacy = '0';

                $tmpq->execute(['account_id' => $account_id,
                                'domain' => $domain_list[$domain_count],
                                'expiry_date' => $expiry_date,
                                'ns1' => $ns1,
                                'ns2' => $ns2,
                                'ns3' => $ns3,
                                'ns4' => $ns4,
                                'ns5' => $ns5,
                                'ns6' => $ns6,
                                'ns7' => $ns7,
                                'ns8' => $ns8,
                                'ns9' => $ns9,
                                'ns10' => $ns10,
                                'autorenew' => $autorenew,
                                'privacy' => $privacy]);

                $domain_count++;

            }

        } else {

            $log_message = 'Unable to get domain list';
            $log_extra = array('API Key' => $this->format->obfusc($api_key), 'Account ID' => $account_id);
            $this->log->error($log_message, $log_extra);

        }

        return array($domain_count, $domain_list);
    }

    public function getFullInfo($account_id, $domain)
    {
        $expiration_date = '';
        $dns_servers = array();
        $privacy_status = '';
        $autorenewal_status = '';

        $tmpq = $this->system->db()->prepare("
            SELECT id, expiry_date, ns1, ns2, ns3, ns4, ns5, ns6, ns7, ns8, ns9, ns10, autorenew, privacy
            FROM domain_queue_temp
            WHERE account_id = :account_id
              AND domain = :domain
            ORDER BY id ASC");
        $tmpq->execute(['account_id' => $account_id,
                        'domain' => $domain]);
        $result = $tmpq->fetch();

        if (!$result) {

            $log_message = 'Unable to get domain details';
            $log_extra = array('Domain' => $domain, 'Account ID' => $account_id);
            $this->log->error($log_message, $log_extra);

        } else {

            $expiration_date = $result->expiry_date;

            $dns_result[0] = $result->ns1;
            $dns_result[1] = $result->ns2;
            $dns_result[2] = $result->ns3;
            $dns_result[3] = $result->ns4;
            $dns_result[4] = $result->ns5;
            $dns_result[5] = $result->ns6;
            $dns_result[6] = $result->ns7;
            $dns_result[7] = $result->ns8;
            $dns_result[8] = $result->ns9;
            $dns_result[9] = $result->ns10;
            $dns_servers = $this->processDns($dns_result);

            $privacy_status = $result->privacy;

            $autorenewal_status = $result->autorenew;

            $tmpq = $this->system->db()->prepare("
                DELETE FROM domain_queue_temp
                WHERE id = :id");
            $tmpq->execute(['id' => $result->id]);

        }

        return array($expiration_date, $dns_servers, $privacy_status, $autorenewal_status);
    }

    public function convertToArray($api_result)
    {
        return json_decode($api_result, true);
    }

    public function processExpiry($expiry_result)
    {
        $unix_expiration_date = strtotime($expiry_result);
        return gmdate("Y-m-d", $unix_expiration_date);
    }

    public function processDns($dns_result)
    {
        $dns_servers = array();
        if (!empty($dns_result)) {
            $dns_servers = array_filter($dns_result);
        } else {
            $dns_servers[0] = 'no.dns-servers.1';
            $dns_servers[1] = 'no.dns-servers.2';
        }
        return $dns_servers;
    }

    public function processAutorenew($autorenewal_result)
    {
        if ($autorenewal_result == 'yes') {
            $autorenewal_status = '1';
        } else {
            $autorenewal_status = '0';
        }
        return $autorenewal_status;
    }

} //@formatter:on