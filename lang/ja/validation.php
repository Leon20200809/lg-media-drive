<?php
return [
    'required' => ':attributeを入力・選択してください。',
    'email' => 'メールアドレスの形式を確認してください。',
    'integer' => ':attributeが無効です。',
    'in' => ':attributeが無効です。',
    'file' => ':attributeを読み込めません。ファイルを選び直してください。',
    'string' => ':attributeを文字で入力してください。',
    'uploaded' => ':attributeを送信できませんでした。サイズや接続を確認してください。',
    'max' => [
        'file' => 'ファイルの送信上限を超えています。',
        'string' => ':attributeは:max文字以内で入力してください。',
    ],
    'min' => ['numeric' => ':attributeが無効です。'],
    'attributes' => [
        'file' => 'ファイル',
        'name' => '名前',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'parent_id' => '親フォルダ',
        'destination' => '移動先',
        'folder' => 'フォルダ',
        'trash' => 'ゴミ箱の指定',
    ],
];
