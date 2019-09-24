<?php

require "vendor/autoload.php";
require "config.php";

use Medoo\Medoo;
// use Tracy\Debugger;

// Debugger::enable();


// token1和token2请到config.php里面设置，用来鉴权。
//所有的api都必须在参数中写token1与token2。否则送403一个。
$token1 = Flight::request()->data->token1;
$token2 = Flight::request()->data->token2;

if ($token1 != $config['token1'] || $token2 != $config['token2']) {
    Flight::halt(403);
}

//同样的 去config.php里面设置。
$db = new Medoo([
    'database_type' => 'mysql',
    'database_name' => $config['database_name'],
    'server' => $config['server'],
    'username' => $config['username'],
    'password' => $config['password'],
    'prefix' => $config['prefix']
]);
//检查填入的信息是否正确
function check($category)
{
    global $db;
    $categoryInfo = $db->select("metas", "mid", [
        "name[=]" => $category,
        "type[=]" => "category"
    ]);
    if (empty($categoryInfo)) {
        Flight::halt(500, "分类不存在。");
    }
}



//每次更新分类信息的函数，单纯用代码的话不用管。
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

//标签信息更新函数，也不用管。
function tagsUpdate($cid, $tags)
{
    global $db;
    $tagsArray = explode(",", $tags);

    foreach ($tagsArray as $line) {
        $info = $db->select("metas", 'mid', [
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
            $mid = $info[0];
        }

        $db->insert("relationships", [
            "cid" => $cid,
            "mid" => $mid
        ]);

        $length = count($db->select("relationships", "*", ["mid" => $mid]));
        $db->update("metas", ["count" => $length], ["mid[=]" => $mid]);
    }
}

//标签与分类信息校正函数，不用管。
function metaNumRefresh()
{
    global $db;
    $metasInfo = $db->select("metas", ['mid']);
    foreach ($metasInfo as $line) {
        $relationshipsInfo = $db->select("relationships", ['cid'], ["mid[=]" => $line]);
        $length = count($relationshipsInfo);
        $db->update("metas", ["count" => $length], ["mid[=]" => $line]);
    }
}

//输出不转码的JSON，保证中文正常输出。
function jsonEncode($data)
{
    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

//获取文章分类与标签信息的函数，不用管。
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


//直接访问的话送他一个404。
Flight::route("/", function () {
    Flight::halt(404);
});


//获取所有文档信息的API
//请求方法：POST
//body形式为：form-data
//参数：
//token1
//token2
//（往下所有API都要这两个参数，下面就不赘述了）
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

//增加文章的API
//请求方法：POST
//body形式为：form-data
//（省略了token1和token2）
//title 文章标题
//text 文章内容
//authorId 作者ID（得去数据库里面查）
//category 就写分类的名字 注意只能是一个名字
//tags 标签，每一个标签用半角逗号分隔
Flight::route("POST /addcontent", function () {
    global $db;
    $title = Flight::request()->data->title;
    $text = Flight::request()->data->text;
    $authorId = Flight::request()->data->authorId;
    $category = Flight::request()->data->category;
    $tags = Flight::request()->data->tags;

    check($category);

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

    echo jsonEncode(["info" => "OK"]);
});

//删除文章的API
//请求方法：POST
//body形式为：form-data
//（省略了token1和token2）
//cid 要删除文章的cid
Flight::route("POST /delcontents", function () {
    global $db;
    $cid = Flight::request()->data->cid;

    $db->delete("contents", ["cid[=]" => $cid]);
    $db->delete("metas", ["cid[=]" => $cid]);
    $db->delete("comments", ["cid[=]" => $cid]);
    metaNumRefresh();
    echo jsonEncode(["info" => "OK"]);
});


Flight::start();
