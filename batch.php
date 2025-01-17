<?php

use Carbon\Carbon;

require(__DIR__.'/vendor/autoload.php');

if (file_exists(__DIR__.'/.env')) {
    $dotenv = Dotenv\Dotenv::createMutable(__DIR__);
    $dotenv->load();
}

$client = new GuzzleHttp\Client(['base_uri' => 'https://slack.com/api/']);
$headers = [
  'Authorization' => 'Bearer '. getenv('SLACK_API_TOKEN'),
];
$query = [
    'limit' => 9999,
];
$res = $client->request('GET', 'conversations.list', ['headers' => $headers, 'query' => $query]);
$channel_list = json_decode($res->getBody().'', true)['channels'];

$report = [];
$total_messages = 0; // Add this line to count total messages
$total_users = []; // Add this line to collect all unique users

foreach ($channel_list as $channel) {
    if ($channel['is_private'] === true) { //プライベートは除く
        continue;
    }

    // channelにjoinしていないといけないらしい。チャンネルリストより全てに加入
    $res = $client->request('POST', 'conversations.join', compact('headers') + [
        'form_params' => [
            'channel' => $channel['id'],
        ]
    ]);

    $res = $client->request('GET', 'conversations.history?channel='.$channel['id'], compact('headers'));
    $messages = json_decode($res->getBody().'', true)['messages'];
    $result = [
        'users' => [],
        'messages' => 0,
        'name' => $channel['name_normalized'],
        'id' => $channel['id']
    ];
    foreach ($messages as $idx => $message) {
        // subtypeがchannel_joinは発言じゃないっぽいので除く
        if (isset($message['subtype']) && $message['subtype'] === 'channel_join') {
            continue;
        }
        if (empty($message['user'])) {
            continue;
        }
        $time = Carbon::createFromTimestamp($message['ts'])->addHour(9);
        if ($idx === 0) {
            $result['updated_at'] = $time->format('n/j H:i');
        }
        if (Carbon::now('Asia/Tokyo')->subHour(168)->diffInSeconds($time, false) >=0) {
            $result['messages']++;
            $total_messages++; // Add this line to increment total messages
            $result['users'][] = $message['user'] ?? '';
            $total_users[] = $message['user'] ?? ''; // Add this line to collect the user
        }
    }
    if (is_int($result['messages']) && $result['messages'] >= 100) {
        $result['messages'] = '99+';
    }
    $result['users'] = count(array_unique($result['users']));
    $report[] = $result;

    sleep(4); // Tier 3 のAPI Limitっぽいので秒間0.5アクセスぐらいにする。バーストはOKって書いてあるけどほんとかなぁ
}
$total_users = count(array_unique($total_users)); // Add this line to count total unique users


$report = collect($report)->filter(function ($result) {
    return isset($result['updated_at']) && $result['users'];
})->sortBy(function ($result) {
    return $result['messages'];
})->reverse();

$message = [
    'blocks' => [
        [
            'type' => 'section',
            'block_id' => 'section1',
            'text' => [
                'type' => 'mrkdwn',
                'text' => '過去1週間の投稿数 :speech_balloon:'.$total_messages.'回 :busts_in_silhouette:'.$total_users.'人' 
            ]
        ]
    ]
];

foreach ($report as $idx => $result) {
    $message['blocks'][] = [
        'type' => 'section',
        'block_id' => 'section'.($idx+2),
        'text' => [
            'type' => 'mrkdwn',
            'text' => '<#'.$result['id'].'> :speech_balloon:'.$result['messages'].'回 :busts_in_silhouette:'.$result['users'].'人'
        ]
    ];
}


$client = new GuzzleHttp\Client();

$res = $client->request('POST', getenv('SLACK_WEBHOOK_ENDPOINT'), [
    'json' => $message,
    'http_errors' => false
]);

var_dump($res->getBody().'');
