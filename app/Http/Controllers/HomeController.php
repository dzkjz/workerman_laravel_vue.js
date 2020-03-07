<?php

namespace App\Http\Controllers;

use App\Message;
use App\User;
use GatewayClient\Gateway;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
        // 设置GatewayWorker服务的Register服务ip和端口
        Gateway::$registerAddress = '127.0.0.1:1238';
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index(Request $request)
    {
        //初始化room_id
        $room_id = $request->get('room_id') ? $request->get('room_id') : '1';
        //session中存入room_id
        session()->put('room_id', $room_id);

        return view('home');
    }

    public function init(Request $request)
    {

        //绑定用户
        $this->bind($request);

        //在线用户
        $this->users();

        //历史记录
        $this->history();

        //进入聊天室
        $this->login();
    }

    public function say(Request $request)
    {
        $content = $request->get('content');

        $data = [
            'type' => 'say',
            'data' => [
                'avatar' => auth()->user()->avatar(),
                'name' => auth()->user()->name,
                'content' => $content,
                'time' => date("Y-m-d H:i:s", time()),
            ],
        ];
        $to_user = User::query()->where('name', $request->get('user_id'))->get()->first();

        if ($to_user) // 如果用户发送了user_id，表示是私聊
        {
            $data['data']['name'] = auth()->user()->name . '对' . $to_user->name . '说:';
            Gateway::sendToUid($to_user->id, json_encode($data));
            Gateway::sendToUid(auth()->user()->id, json_encode($data));

            //私聊消息，只发给对应用户，不存储数据库
            return;
        }


        //只发送个对应房间
        Gateway::sendToGroup(session('room_id'), json_encode($data));

        //存入数据库，用户以后查询聊天记录：

        Message::create([
            'user_id' => auth()->user()->id,
            'room_id' => session('room_id'),
            'content' => $content,
        ]);
    }

    private function bind(Request $request)
    {
        //获取当前用户的id
        $id = auth()->user()->id;
        //获取socket中的client id
        $client_id = $request->get('client_id');

        //绑定这两个id
        Gateway::bindUid($client_id, $id);

        //当前用户进入的组别绑定
        Gateway::joinGroup($client_id, session('room_id'));

        //绑定数据到gateway的session
        Gateway::setSession($client_id, [
            'id' => $id,
            'avatar' => auth()->user()->avatar(),
            'name' => auth()->user()->name,
        ]);
    }

    private function login()
    {
        //
        $data = [
            'type' => 'say',
            'data' => [
                'avatar' => auth()->user()->avatar(),
                'name' => auth()->user()->name,
                'content' => '进入了聊天室',
                'time' => date("Y-m-d H:i:s", time()),
            ],
        ];

        //数据发送出去
        Gateway::sendToGroup(session('room_id'), json_encode($data));
    }

    /**
     * 最新的5条历史记录
     */
    private function history()
    {
        $data = ['type' => 'history'];

        $messages = Message::query()
            ->with('user')
            ->where('room_id', session('room_id'))//只查询当前房间的历史记录
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();
        $data['data'] = $messages->map(function ($message) {
            return [
                'avatar' => $message->user->avatar(),
                'name' => $message->user->name,
                'content' => $message->content,
                'time' => $message->created_at->format("Y m d H:i:s"),
            ];
        });

        //发送历史给当前用户，因为请求的时候，只有当前用户需要这个历史记录
        //没有必要一个用户刷新就重新取历史记录发给其他用户
        Gateway::sendToUid(auth()->id(), json_encode($data));
    }

    private function users()
    {
        $data = [
            'type' => 'users',
            'data' => Gateway::getAllClientSessions()
        ];

        Gateway::sendToGroup(session('room_id'), json_encode($data));
    }
}
