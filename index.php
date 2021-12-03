<?php

#get an auth token
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, 'https://auth.laterpay.net/oauth2/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);

#test mode
curl_setopt($ch, CURLOPT_USERPWD, 'client.25666a98-2224-44e3-9e0f-d13f41cc9153:xu.twv_m9BVJuCpaQrOEtgDaSz');

$headers[] = 'Content-Type: application/x-www-form-urlencoded';
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials&scope=read%20write");

$result = curl_exec($ch);
if (curl_errno($ch)) {
    echo 'Error:' . curl_error($ch);
}
curl_close($ch);

$access_token = json_decode($result);



#Functions to make calls to Contribute.to.

#POST add to tab function
function tabAdd($purchase, $access) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://tapi.laterpay.net/v1/purchase');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    $headers = ['Content-Type: application/json','Authorization: Bearer ' . $access->access_token];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($purchase));

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    return json_decode($result);
}

#GET check access to item
function checkAccess($item, $access) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://tapi.laterpay.net/v1/access?user_id='.$item->user_id .'&offering_id='.$item->offering_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $headers = ['Content-Type: application/json','Authorization: Bearer ' . $access->access_token];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }   
    curl_close($ch);
    return json_decode($result);
}


#GET tab info
function getTab($userid,$access) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://tapi.laterpay.net/v1/tabs?user_id=' . $userid);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $headers = ['Content-Type: application/json','Authorization: Bearer ' . $access->access_token];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    return json_decode($result);
}

#End Functions


#Main

# add the item to the tab
# price is always $0.50USD (may be $1 for testing purposes)
if ($_GET['action'] == 'add') {

    #set up purchase object
    $purchase = (object) [
        'user_id' => $_GET['userid'],
        'offering_id' => $_GET['pageid'],
        'payment_model' => "pay_merchant_later",
        'price' => ['amount' => 50,
                    'currency' => 'USD'
                ],
        'metadata' =>['url' => $_GET['url']],
        'sales_model' => 'single_purchase',
        'summary' => substr($_GET['pagetitle'],0,100)
    ];

    #check to see if the user already has access to the item 
    #so we dont double charge them.
    #If they already paid for the item, redirect them back to the page.

    $checkAccess = checkAccess($purchase,$access_token);
    if ($checkAccess->{'access'}->{'has_access'} == 'true') {
        header("Location: " . $_GET['url']);
        //print_r ($checkAccess);
        die; // dont process the rest, ie: dont add this to their tab again.
    }
     
     
    #Add the item to the tab send the request to laterpay
    $addTab = tabAdd($purchase,$access_token);

    #convert the tab amount to decimal currency since we're only dealing with USD for now
    $addTab->{'tab'}->{'total'} = number_format((float)$addTab->{'tab'}->{'total'}/100,2,'.','');

    #If the tab is full, print out the list of items and get the stripe payment intent.
    if ($addTab->tab->status == "full") {
	    $stripe_id = paymentIntent($addTab->tab->id,$access_token);
        $jsonData->status = "full";
        $jsonData->paymentIntent = $stripe_id;
        $jsonData->tab = $addTab->tab->purchases;
	    print json_encode($jsonData);
    }
    #Otherwise add the item to the tab (if it is not full)
    elseif ($addTab->{'detail'}->{'item_added'}) {
            print "{\"success\":true,\"tab\":\"".$addTab->{'tab'}->{'total'}."\",\"url\":\"".$_GET['url']."\"}";
    }
    #Catch other errors
    else {
        print "item not added! Reason: " . print_r($addTab);
    }


}

#Check if the user has access to the item (on paywall load)
elseif ($_GET['action'] == 'access') {

    #set up the item object to post to Laterpay
	$item = (object) [
		'user_id' => $_GET['userid'],
		'offering_id' => $_GET['pageid']
	];
     
    #send the request to laterpay
    $checkAccess = checkAccess($item,$access_token);
    
    #If they have access return access:true JSON (used by TN to hide the paywall)
    if ($checkAccess->{'access'}->{'has_access'} == 'true') {
        print "{\"access\": true}";
    }
    #Otherwise reture the access:false JSON (used by TN to show the paywall)
    else {
	    $tab = getTab($_GET['userid'], $access_token);
        print "{\"access\":false,\"tabtotal\": \"". $tab[0]->total ."\"}";
    }
}

#End main

?>
