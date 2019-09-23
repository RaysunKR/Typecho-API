<?php

require "vendor/autoload.php";
require "config.php";

use Medoo\Medoo;
// use Tracy\Debugger;

// Debugger::enable();

$token1 = Flight::request()->data->token1;
$token2 = Flight::request()->data->token2;

if ($token1 != $config['token1'] || $token2 != $config['token2']) {
    Flight::halt(403);
}

$db = new Medoo([
    'database_type' => 'mysql',
    'database_name' => $config['database_name'],
    'server' => $config['server'],
    'username' => $config['username'],
    'password' => $config['password'],
    'prefix' => $config['prefix']
]);

function categoryUpdate($cid, $category)
{
    global $db;
    $categoryInfo = $db->select("metas", "mid", [
        "name[=]" => $category,
        "type[=]" => "category"
    ]);

    if (empty($categoryInfo)) {
        Flight::halt(500, "分类不存在。");
    }

    $mid = $categoryInfo[0];


    if (empty($db->select("relationships", '*', ["cid[=]" => $cid, "mid[=]" => $mid]))) {
        $db->insert("relationships", [
            "cid" => $cid,
            "mid" => $mid
        ]);
    } else {
        $db->update("relationships", [
            "mid" => $mid
        ], [
            "cid[=]" => $cid
        ]);
    }


    $relationshipsInfo = $db->select("relationships", "*", ["mid[=]" => $mid]);
    $length = count($relationshipsInfo);
    $db->update("metas", ["count" => $length], ["mid[=]" => $mid]);
}

function tagsUpdate($cid, $tags)
{
    global $db;
    $tagsArray = explode(",", $tags);

    foreach ($tagsArray as $line) {
        $info = $db->select("metas", ['mid'], [
            "name[=]" => $line,
            "type[=]" => "tag"
        ]);
        $mid = 0;
        if (empty($info)) {
            $db->insert("metas", [
                "name" => $line,
                "slug" => $line,
                "type" => "tag",
                "count" => 0,
                "order" => 0,
                "parent" => 0,
            ]);
            $mid = $db->id();
        } else {
            $mid = $info[0]['mid'];
        }

        $db->insert("relationships", [
            "cid" => $cid,
            "mid" => $mid
        ]);

        $length = count($db->select("relationships", "*", ["mid" => $mid]));
        $db->update("metas", ["count" => $length], ["mid[=]" => $mid]);
    }
}

function metaNumRefresh()
{
    global $db;
    $metasInfo = $db->select("metas", ['mid']);
    foreach ($metasInfo as $line) {
        $relationshipsInfo = $db->select("relationships", ['cid'],["mid[=]"=>$line]);
        $length = count($relationshipsInfo);
        $db->update("metas", ["count" => $length], ["mid[=]" => $line]);
    }
}

function jsonEncode($data)
{
    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

function getMetasInfo($cid)
{
    global $db;
    $relationshipsInfo = $db->select("relationships", "mid", ["cid[=]" => $cid]);
    $metasInfo = array(
        "category" => "",
        "tags" => array()
    );

    foreach ($relationshipsInfo as $mid) {
        $meta = $db->select("metas", ["name", "type"], ["mid[=]" => $mid]);
        if ($meta[0]["type"] == "category") {
            $metasInfo["category"] = $meta[0]["name"];
        } else {
            array_push($metasInfo['tags'], $meta[0]["name"]);
        }
    }

    return $metasInfo;
}



Flight::route("/", function () {
    Flight::halt(404);
});

Flight::route("POST /getcontent", function () {
    global $db;
    $contentData = $db->select('contents', "*");

    $i = 0;
    foreach ($contentData as $line) {
        $metasInfo = getMetasInfo($line['cid']);
        $contentData[$i]["category"] = $metasInfo['category'];
        $contentData[$i]["tags"] = $metasInfo['tags'];
        $i++;
    }

    echo jsonEncode($contentData);
});

Flight::route("POST /addcontent", function () {
    global $db;
    $title = Flight::request()->data->title;
    $text = Flight::request()->data->text;
    $authorId = Flight::request()->data->authorId;
    $category = Flight::request()->data->category;
    $tags = Flight::request()->data->tags;

    $db->insert("contents", [
        "title" => $title,
        "created" => time(),
        "modified" => time(),
        "text" => $text,
        "order" => 0,
        "authorId" => $authorId,
        "type" => "post",
        "status" => "publish",
        "commentsNum" => 0,
        "allowComment" => 1,
        "allowPing" => 1,
        "parent" => 0,
        "views" => 0
    ]);

    $cid = $db->id();

    $db->update(
        "contents",
        [
            "slug" => $cid
        ],
        [
            "cid[=]" => $cid

        ]
    );

    categoryUpdate($cid, $category);
    tagsUpdate($cid, $tags);
    metaNumRefresh();

    echo jsonEncode(["info"=>"OK"]);
});

Flight::route("POST /delcontents",function(){
    global $db;
    $cid=Flight::request()->data->cid;

    $db->delete("contents",["cid[=]"=>$cid]);
    $db->delete("metas",["cid[=]"=>$cid]);
    $db->delete("comments",["cid[=]"=>$cid]);
    metaNumRefresh();
    echo jsonEncode(["info"=>"OK"]);
});


Flight::start();
