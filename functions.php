<?php

// Set the Subsidiary for prices.
function set_sub(){
    // default sub = FR. MA, TN, DE, IT, .. available
    if (isset($_GET['sub'])) {
        $subsidiary = $_GET['sub'];
    }
    else {
        $subsidiary = 'FR';
    }
    return $subsidiary;
}

// list subs
function list_subs(){
    $subs = array('CZ','DE','ES','FI','FR','GB','IE','IT','LT','MA','NL','PL','PT','SN','TN');
    foreach($subs as $sub){
        echo '/ <a href="/?sub='.$sub.'">'.$sub.'</a> ';
    }
}


// CURL GET call to retrieve OVHcloud API results
function get_from($url, $sub){
    $ch = curl_init();
    //configure CURL
    // NB : it's dirty, should use OVH wrapper instead, but it's ok for a PoC. Go to https://github.com/ovh/php-ovh for more infos.
    curl_setopt_array($ch, array(
        CURLOPT_URL => 'https://api.ovh.com/1.0/'.$url.$sub,
        CURLOPT_HTTPHEADER => array('Content-type: application/json'),
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_RETURNTRANSFER => true
    ));
    $result = curl_exec($ch);
    curl_close($ch);

    //convert JSON data back to PHP array and return it
    return json_decode($result, true);
}

// GET previously generated JSON in cache. Since the API call could be long, we cache the generated JSON output for a 10 minutes duration
// It requires a "cache" folder with read-write access
function get_json_cache($sub){
    
    // starting the cache, different one for each subsidiary (due to prices)
    $cache = 'cache/ovhcloud_servers_pricelist_'.$sub.'.json';
    $expire = time() -60*10 ; // Expiry time = now - 10 minutes

    // If the cache exists, we return the cached JSON
    if(file_exists($cache) && filemtime($cache) > $expire)
    {
            $result = file_get_contents($cache);
            //convert JSON data back to PHP array
            return json_decode($result, true);
    }
    // If the cache does NOT exist, we return null
    else
    {
        return null;
    }

}

// Find the cache time update to show it on the frontpage.
function get_cache_time($sub){
    
    $cache = 'cache/ovhcloud_servers_pricelist_'.$sub.'.json';
    date_default_timezone_set('Europe/Paris');
    
    if(file_exists($cache)) {
        $update = "Last modified: ".date("F d Y H:i:s",filemtime($cache));
    }
    else {
        $update = "Last modified: unknown";
    }

    return $update;
}


// Set the currency for the prices informations.
function get_currency($json){
    // Get the currency found inside the JSON
    $currency = end($json);
    return $currency;
}


// Get availabilities per server and per location.
function get_availabilities($json_availabilities, $fqn){
    // Each variation of a server is related to a FQN (planCode.memoryAddon.StorageAddon)
    // We look after a match, if we find it we return "datacenters" array who contains avalaibility per datacenter
    foreach($json_availabilities as $item){
        if ($item['fqn'] == $fqn){
            return $item['datacenters'];
        }
    };
}

// Parse
function parse_plans($subsidiary){
    
    // before everything, we check if we have the a JSON in cache.
    $cached_plans = get_json_cache($subsidiary);
    if (!empty($cached_plans)) {
        return $cached_plans;
    }
    else {
        // Retrieve JSON for the products, plans, addons, pricings from OVH API
        $json = get_from('order/catalog/public/baremetalServers?ovhSubsidiary=', $subsidiary);

        // Retrieve JSON for the datacenters availabilities
        $json_availabilities = get_from('dedicated/server/datacenter/availabilities', '');
        
        // Store the currency code
        $currency = $json['locale']['currencyCode'];

        // Main loop. We parse all plans and aggregate informations such as prices and technical specifications
        foreach($json['plans'] as $item){


            // MEMORY + STORAGE addons listing
            // 1 plan can contain multiple storage and memory addons. So we retrieve the addons lists here.
            // They does not contain technical specifications, we need to retrieve them in another loop.
            foreach($item['addonFamilies'] as $addon_family){
                if($addon_family['name'] == 'memory'){
                    $memory_addons = $addon_family['addons'];
                }
                 if($addon_family['name'] == 'storage' OR $addon_family['name'] == 'disk'){
                    $storage_addons = $addon_family['addons'];
                }
            }


            // CPU + FRAME + RANGE informations
            // These informations are stored in the "products" part of the JSON, not in the "plans".
            $products_specs[] = array();
            foreach($json['products'] as $prod){
                // we storage cpu, frame, range informations
                if($prod['name'] == $item['planCode'] OR $prod['name'] == $item['product']) {
                    $tech_specs = $prod['blobs']['technical']['server'];
                }
                // we also store exact specification of memory and storage addons
                $products_specs[$prod['name']] = array(
                    'name' => $prod['name'],
                    'specs' => $prod['blobs']['technical']
                );
            }

            // each server has multiples pricings (no commitment, 12 or 24 months commitment, etc)
            // i chose to display only priceing with no commitment
            foreach($item['pricings'] as $pricing){
                if($pricing['commitment'] == 0 && $pricing['mode'] == 'default' && $pricing['interval'] == 1 ){
                    $server_price = $pricing['price'] / 100000000;
                }
            }


            // ADDONS LOOP
            // to generate a clean HTML table, I need 1 array per server derivative. If a server has 2 memory addons and 8 storage addons, I want to generate 16 lines.
            // It's a choice. I want a table with all the derivatives, directly.
            foreach($memory_addons as $memory_addon){
                foreach($storage_addons as $storage_addon){


                    // ADDONS TECHNICAL SPECIFICATIONS + PRICES
                    // We retrieved addons listings before, now we retrieve their pricings and product name
                    foreach($json['addons'] as $addon){
                        foreach($addon['pricings'] as $pricing){
                            if($pricing['commitment'] == 0 && $pricing['mode'] == 'default' && $pricing['interval'] == 1 ){
                                $addon_price = $pricing['price'] / 100000000;
                            }
                        }
                        if($addon['planCode'] == $storage_addon){
                            $storage_specs = array(
                                'planCode' => $addon['planCode'],
                                'product' => $addon['product'],
                                'invoiceName' => $addon['invoiceName'],
                                'specifications' => $products_specs[$addon['product']]['specs']['storage'],
                                'price' => $addon_price
                            );
                        }
                        if($addon['planCode'] == $memory_addon){
                            $memory_specs = array(
                                'planCode' => $addon['planCode'],
                                'product' => $addon['product'],
                                'invoiceName' => $addon['invoiceName'],
                                'specifications' => $products_specs[$addon['product']]['specs']['memory'],
                                'price' => $addon_price
                            );
                        }
                    }


                    // AVAILABILITIES
                    // availabilities are defined per fully qualified name (FQN) as below :
                    $fqn = $item['planCode'].".".$memory_specs['product'].".".$storage_specs['product'];
                    // then we retrieve it. We will get a list of datacenters and availabilities
                    $availabilities = get_availabilities($json_availabilities, $fqn);

                    // Aggregation of all informations in a array
                    // This array will be the main source to generate HTML Table in index.php
                    // 1 plan = 1 product to show. approx 1300 entries
                    $plans[] = array(
                        'planCode' => $item['planCode'],
                        'product' => $item['product'],
                        'invoiceName' => $item['invoiceName'],
                        'fqn' => $fqn,
                        'memory' => $memory_specs,
                        'storageSpecs' => $storage_specs,
                        'cpu' => $tech_specs['cpu'],
                        'range' => $tech_specs['range'],
                        'frame' => $tech_specs['frame'],
                        'price' => $server_price + $storage_specs['price'] + $memory_specs['price'],
                        'availabilities' => $availabilities
                    );

                }
            } // END OF ADDONS LOOP
        } // END OF MAIN LOOP
        
        // Store the currency at the end of the JSON
        $plans[] = $currency;
        
        // Sotre the array in a JSON file
        file_put_contents('cache/ovhcloud_servers_pricelist_'.$subsidiary.'.json',json_encode($plans));
        
        // return the array to the main page to generate HTML table
        return $plans;
    } // END of else
    //print_r($plans);
}

//parse_plans('FR');

?>