<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$lib = $root . '/modules/addons/serverspanlogicboxestools/lib';
foreach (['ApiException','Support','ApiClient','Crypto','Schema','AccountRepository','AuditLogger','LockRepository','JobRepository','CustomerService','DomainService','PricingEngine','PricingService','PromoService','TransferService','AutomationService','Renderer','Controller','ClientController'] as $class) {
    require_once $lib . '/' . $class . '.php';
}

use ServerSpan\LogicBoxesTools\ApiClient;
use ServerSpan\LogicBoxesTools\ApiException;
use ServerSpan\LogicBoxesTools\DomainService;
use ServerSpan\LogicBoxesTools\PricingEngine;
use ServerSpan\LogicBoxesTools\PromoService;
use ServerSpan\LogicBoxesTools\Support;
use ServerSpan\LogicBoxesTools\AccountRepository;
use ServerSpan\LogicBoxesTools\CustomerService;
use ServerSpan\LogicBoxesTools\AuditLogger;

$tests = 0;
$failures = [];
function ok(bool $condition, string $name): void { global $tests,$failures; ++$tests; if(!$condition)$failures[]=$name; }
function eq(mixed $actual,mixed $expected,string $name): void { ok($actual===$expected,$name.' expected='.var_export($expected,true).' actual='.var_export($actual,true)); }
function throws(callable $fn,string $class,string $name): void { try{$fn();ok(false,$name.' did not throw');}catch(Throwable $e){ok($e instanceof $class,$name.' threw '.get_class($e));} }

// Support / security primitives
eq(Support::canonicalDomain(' Example.COM. '),'example.com','canonical domain');
eq(Support::splitName('Andrei Cojan'),['Andrei','Cojan'],'split name');
eq(Support::splitName('Madonna'),['Madonna','-'],'single name');
ok(Support::bool('YES'),'bool yes');
ok(!Support::bool('no'),'bool no');
$p=Support::randomPassword(32); ok(strlen($p)===32,'password length'); ok((bool)preg_match('/[a-z]/',$p),'password lower'); ok((bool)preg_match('/[A-Z]/',$p),'password upper'); ok((bool)preg_match('/[0-9]/',$p),'password digit'); ok((bool)preg_match('/[!@#_-]/',$p),'password symbol');
$r=Support::redact(['api-key'=>'secret','nested'=>['password'=>'x','safe'=>'yes']]); eq($r['api-key'],'[REDACTED]','redact api key'); eq($r['nested']['password'],'[REDACTED]','redact nested'); eq($r['nested']['safe'],'yes','keep safe');
$flat=Support::flattenRows(['1'=>['orders.orderid'=>'123','entity.description'=>'x.com']],'orderid'); eq(count($flat),1,'flatten dotted orderid'); eq($flat[0]['orders.orderid'],'123','flatten value');
eq(Support::firstValue(['entity.currentstatus'=>'Active'],['status','entity.currentstatus'],'x'),'Active','first dotted explicit key');
[$cc,$phone]=Support::normalizePhone('+40722123456','RO'); eq($cc,'40','phone country code'); eq($phone,'722123456','phone local');
throws(fn()=>Support::normalizePhone('','RO'),InvalidArgumentException::class,'empty phone rejected');

// API client transport, bounds, auth, errors, scalar JSON.
$calls=[];
$transport=function(string $method,string $url,array $params) use (&$calls):array{$calls[]=[$method,$url,$params];return ['status'=>200,'content_type'=>'application/json','body'=>'{"recsindb":0}'];};
$api=new ApiClient('https://test.httpapi.com/api',123,'sekret',$transport);
$api->searchCustomers(0,999);
eq($calls[0][0],'GET','search method'); eq($calls[0][2]['page-no'],1,'page clamped'); eq($calls[0][2]['no-of-records'],500,'limit clamped'); eq($calls[0][2]['auth-userid'],123,'auth userid'); eq($calls[0][2]['api-key'],'sekret','auth key');
throws(fn()=>new ApiClient('http://example.test/api',1,'x'),InvalidArgumentException::class,'http rejected');
$scalarApi=new ApiClient('https://test.httpapi.com/api',1,'x',fn()=>['status'=>200,'content_type'=>'application/json','body'=>'"abc123"']); eq($scalarApi->customerLoginToken(9,'127.0.0.1'),'abc123','json scalar token');
throws(fn()=>$scalarApi->customerLoginToken(9,'not-ip'),InvalidArgumentException::class,'invalid SSO ip');
$errApi=new ApiClient('https://test.httpapi.com/api',1,'x',fn()=>['status'=>200,'content_type'=>'application/json','body'=>'{"status":"ERROR","message":"bad"}']); throws(fn()=>$errApi->ping(),ApiException::class,'api status error');
$httpErr=new ApiClient('https://test.httpapi.com/api',1,'x',fn()=>['status'=>429,'content_type'=>'application/json','body'=>'{"message":"rate"}']); throws(fn()=>$httpErr->ping(),ApiException::class,'http error');
$boolApi=new ApiClient('https://test.httpapi.com/api',1,'x',fn()=>['status'=>200,'content_type'=>'application/json','body'=>'true']); ok($boolApi->modifyCustomer([]),'boolean modify response');
$signupApi=new ApiClient('https://test.httpapi.com/api',1,'x',fn()=>['status'=>200,'content_type'=>'application/json','body'=>'998877']); eq($signupApi->signupCustomer([]),998877,'numeric signup response');

// Pricing engine.
$engine=new PricingEngine();
$pricing=['domains'=>['dotcom'=>['addnewdomain'=>['1'=>'10','2'=>'19'],'renewdomain'=>['1'=>'11'],'addtransferdomain'=>['1'=>'9'],'restoredomain'=>['1'=>'70']]],'hosting'=>['plan'=>['add'=>['12'=>4]]]];
$m=$engine->extractDomainMatrices($pricing); ok(isset($m['dotcom']),'extract domain product'); eq($m['dotcom']['register'][1],10.0,'register parse'); eq($m['dotcom']['renew'][1],11.0,'renew parse'); eq($m['dotcom']['transfer'][1],9.0,'transfer parse'); eq($m['dotcom']['restore'][1],70.0,'restore parse'); ok(!isset($m['plan']),'ignore hosting');
eq($engine->resolveProductTld('dotcom',[],[],['com'=>[]]),'.com','dot product heuristic'); eq($engine->resolveProductTld('weird',[],['weird'=>'.co.uk'],[]),'.co.uk','manual tld map'); eq($engine->resolveProductTld('thirdleveldotau',[],[],['com.au'=>[],'net.au'=>[]]),null,'ambiguous grouped product not guessed');
$sell=$engine->buildSellingMatrix($m['dotcom'],['source'=>'cost','margin_type'=>'percent','margin'=>25,'round_to'=>0.05,'round_mode'=>'up'],1); eq($sell['register'][1],12.5,'percent margin'); eq($sell['register'][2],23.75,'commercial round up');
$sell2=$engine->buildSellingMatrix(['register'=>[1=>1000],'renew'=>[],'transfer'=>[],'restore'=>[]],['source'=>'customer','round_to'=>0.01],1000); eq($sell2['register'][1],1.0,'currency multiplier');
$sell3=$engine->buildSellingMatrix(['register'=>[1=>10],'renew'=>[],'transfer'=>[],'restore'=>[]],['source'=>'cost','margin_type'=>'fixed','margin'=>2,'round_to'=>0.01],1); eq($sell3['register'][1],12.0,'fixed margin');
$many=['register'=>array_fill_keys(range(1,12),10),'renew'=>array_fill_keys(range(1,12),11),'transfer'=>[1=>9,2=>17],'restore'=>[]]; $payload=$engine->buildWhmcsPayload('.com',$many,['registrar_module'=>'resellerclub','currency'=>'USD'],['com'=>['privacy_available'=>true]]); eq(count($payload['register']),10,'WHMCS register max 10'); eq(count($payload['renew']),9,'WHMCS renew max 9'); eq(count($payload['transfer']),1,'WHMCS transfer one period'); ok($payload['id_protection']===true,'privacy mapping');
eq($engine->findTldForDomain('foo.example.co.uk',['com','.uk','.co.uk']),'.co.uk','longest TLD match'); eq($engine->priceForPeriod(['renew'=>['2'=>'22.50']],'renew',2),22.5,'price period'); eq($engine->priceForPeriod(['renew'=>[]],'renew',2),null,'missing price');

// Promo parser does not need WHMCS runtime.
$promos=new PromoService();
$normalized=$promos->normalizePromotions(['x'=>['productkey'=>'dotcom','actiontype'=>'addnewdomain','period'=>'1','customerprice'=>'7.99','resellerprice'=>'6.00','barrierprice'=>'5.00','serviceprovidersellingcurrency'=>'USD','starttime'=>'1700000000','endtime'=>'1800000000','isactive'=>'true']]);
eq(count($normalized),1,'promo normalize count'); eq($normalized[0]['product_key'],'dotcom','promo product'); eq($normalized[0]['customer_price'],7.99,'promo price'); ok($normalized[0]['is_active'],'promo active'); ok(str_starts_with((string)$normalized[0]['starts_at'],'2023-'),'promo timestamp');

// Domain response parser: dotted keys and verification state are common in LogicBoxes responses.
$accounts=new AccountRepository(); $audit=new AuditLogger(); $customers=new CustomerService($accounts,$audit); $domains=new DomainService($accounts,$customers,$audit);
$dn=$domains->normalizeDomain(['orders.orderid'=>'88','entity.description'=>'Example.COM','entity.customerid'=>'12','entity.currentstatus'=>'Pending Verification','entitytype.entitytypekey'=>'dotcom','orders.endtime'=>'1800000000']);
eq($dn['order_id'],88,'domain order normalize'); eq($dn['domain'],'example.com','domain name normalize'); eq($dn['customer_id'],12,'domain customer normalize'); eq($dn['product_key'],'dotcom','domain product normalize'); eq($dn['status'],'Pending Verification','domain status normalize');
$rows=$domains->normalizeSearchRows(['records'=>[['orders.orderid'=>'88','entity.description'=>'example.com'],['orders.orderid'=>'89','entity.description'=>'example.net']]]); eq(count($rows),2,'recursive domain rows');

// Static security/packaging assertions.
$all=''; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile())$all.=file_get_contents($f->getPathname())."\n";}
$runtime=''; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/modules',FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile())$runtime.=file_get_contents($f->getPathname())."\n";}
ok(!str_contains(strtolower($runtime),'resellerclub-mods.com'),'no proprietary vendor backlink/reference');
ok(!str_contains($runtime,'mb_substr('),'no mbstring runtime dependency');
ok(!str_contains($runtime,'CURLOPT_SSL_VERIFYPEER => false'),'TLS verification never disabled');
ok(!preg_match('/(?:eval|base64_decode)\s*\(/i',$runtime),'no eval/base64 decoder');
ok(str_contains($runtime,'allow_unattended_financial_writes'),'financial automation second gate');
ok(str_contains($runtime,'before_json'),'rollback snapshots present');
ok(str_contains($runtime,'PreDeleteClient'),'modern pre-delete hook');
ok(!str_contains($runtime,'ClientDelete\''),'deprecated client delete hook absent');
ok(str_contains($runtime,'generate-login-token.json'),'short-lived SSO token endpoint');
ok(str_contains($runtime, "'passwd' => Support::randomPassword") || str_contains($runtime, "\$payload['passwd'] = \$password"),'signup uses generated upstream password');
ok(!preg_match('/Client(ChangePassword|PasswordChange)|customers\/change-password/i',$runtime),'no customer password synchronization path');
$module=file_get_contents($root.'/modules/addons/serverspanlogicboxestools/serverspanlogicboxestools.php'); ok(str_contains($module,"'version' => '1.0.0-beta.1'"),'module version'); ok(str_contains($module,'Schema::install'),'activation schema');
$json=json_decode(file_get_contents($root.'/module.json'),true); ok(is_array($json),'module json valid'); eq($json['version']??null,'1.0.0-beta.1','manifest version');

if($failures){fwrite(STDERR,"FAILED ".count($failures)." / $tests tests:\n- ".implode("\n- ",$failures)."\n");exit(1);} echo "OK - $tests LogicBoxes Tools self-tests passed\n";
