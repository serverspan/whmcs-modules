<?php
/**
 * ServerSpan PowerDNS Manager - English language file
 * Location: modules/addons/pdnsmanager/lang/english.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

$_ADDONLANG['page_title']      = 'DNS Manager';
$_ADDONLANG['zones_title']     = 'Your DNS Zones';
$_ADDONLANG['domain']          = 'Domain';
$_ADDONLANG['actions']         = 'Actions';
$_ADDONLANG['manage']          = 'Manage';
$_ADDONLANG['create_zone']     = 'Create Zone';
$_ADDONLANG['check_ns']        = 'Check NS';
$_ADDONLANG['no_domains']      = 'You have no domains eligible for DNS management.';
$_ADDONLANG['records']         = 'DNS Records';
$_ADDONLANG['back']            = 'Back to zones';
$_ADDONLANG['name']            = 'Name';
$_ADDONLANG['type']            = 'Type';
$_ADDONLANG['content']         = 'Content';
$_ADDONLANG['protected']       = 'Protected';
$_ADDONLANG['edit']            = 'Edit';
$_ADDONLANG['delete']          = 'Delete';
$_ADDONLANG['confirm_delete']  = 'Delete this record set?';
$_ADDONLANG['add_record']      = 'Add / Edit Record Set';
$_ADDONLANG['record_hint']     = 'One value per line. Saving replaces the full set for this name and type.';
$_ADDONLANG['save']            = 'Save Record';
$_ADDONLANG['import_export']   = 'Import / Export';
$_ADDONLANG['export']          = 'Export Zone File';
$_ADDONLANG['import']          = 'Import Zone';
$_ADDONLANG['import_hint']     = 'Paste a BIND-format zone file here';
$_ADDONLANG['dnssec_check']    = 'Check DNSSEC Status';
$_ADDONLANG['dnssec_enable']   = 'Enable DNSSEC';
$_ADDONLANG['dnssec_disable']  = 'Disable DNSSEC';
$_ADDONLANG['ds_hint']         = 'Add these DS records at your registrar:';
$_ADDONLANG['zone_created']    = 'DNS zone created.';
$_ADDONLANG['zone_missing']    = 'No DNS zone exists for this domain yet.';
$_ADDONLANG['not_owner']       = 'You do not own this domain.';
$_ADDONLANG['bad_type']        = 'Unsupported record type.';
$_ADDONLANG['apex_guard']      = 'Apex NS/CNAME records cannot be changed here.';
$_ADDONLANG['no_content']      = 'Record content is required.';
$_ADDONLANG['record_saved']    = 'Record saved.';
$_ADDONLANG['record_deleted']  = 'Record set deleted.';
$_ADDONLANG['import_empty']    = 'No importable records found (SOA and apex NS are skipped).';
$_ADDONLANG['import_done']     = 'Imported :count record sets.';
$_ADDONLANG['dnssec_on']       = 'DNSSEC enabled.';
$_ADDONLANG['dnssec_off']      = 'DNSSEC disabled.';
