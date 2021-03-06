<?php

namespace Gini\Controller\CGI\AJAX\Gapper\Auth\Gateway\Step;

class User401 extends \Gini\Controller\CGI
{
    public function __index()
    {
        $appIds = (array) \Gini\Config::get('app.auto_install_apps_for_user');
        if (empty($appIds)) {
            return \Gini\IoC::construct('\Gini\CGI\Response\JSON', T('您无权访问该应用，请联系系统管理员.'));
        }
        $myClientID = \Gini\Gapper\Client::getId();
        if ($myClientID && !in_array($myClientID, $appIds)) {
            return \Gini\IoC::construct('\Gini\CGI\Response\JSON', T('您无权访问该应用，请联系系统管理员!'));
        }

        $appInfo = \Gini\Gapper\Client::getInfo();
        if (!$appInfo['id']) {
            return $this->_showError();
        }

        $identity = $_SESSION['gapper-auth-gateway.username'];
        $gapperRPC = \Gini\Gapper\Client::getRPC();
        if (!$identity && !\Gini\Gapper\Client::getUserName()) {
            return $this->_showError();
        }
        elseif(!$identity) {
            $identity = $gapperRPC->gapper->user->getIdentity(\Gini\Gapper\Client::getUserName(), \Gini\Config::get('app.node'));
        }

        $config = (object)\Gini\Config::get('gapper.auth')['gateway'];
        $userInfo = $this->_getUserInfo($identity);
        if (!$userInfo->ref_no) {
            //unset($_SESSION['gapper-auth-gateway.username']);
            return \Gini\IoC::construct('\Gini\CGI\Response\JSON', $config->tips['nobody']);
        }

        $username = \Gini\Gapper\Client::getUserName();
        $user = \Gini\Gapper\Client::getUserInfo();
        if ($username) {
           if (!$gapperRPC->gapper->app->installTo($myClientID, 'user', $user['id'])) {
               return \Gini\IoC::construct('\Gini\CGI\Response\JSON', T('您无权访问该应用，请联系系统管理员'));
           }

           \Gini\Gapper\Client::getUserApps(\Gini\Gapper\Client::getUserName(), true);
           
           return \Gini\IoC::construct('\Gini\CGI\Response\JSON', true);
        }
        // TODO 参考Group401, 引导用户创建gapper用户

        $form = $this->form('post');
        if (isset($form['email'])) {
            $name = trim($form['name']);
            $email = trim($form['email']);
            $nextVal = true;

            $validator = new \Gini\CGI\Validator();
            try {
                if (!\Gini\Gapper\Client::getUserName()) {
                    $validator
                        ->validate('name', $name, T('请输入真实姓名'))
                        ->validate('email', function() use ($email, &$nextVal) {
                            $pattern = '/^([a-zA-Z0-9_\.\-])+\@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,4})+$/';
                            if (!preg_match($pattern, $email)) {
                                $nextVal = false;
                                return false;
                            }
                            return true;
                        }, T('请使用正确的Email'))
                        ->validate('email', function() use (&$nextVal, $form, $gapperRPC) {
                            if (!$nextVal) return true;
                            $identityUser = $gapperRPC->gapper->user->getInfo($form['email']);
                            if (!empty($identityUser) && ($form['password'] === '' || !array_key_exists('password', $form))) {
                                $nextVal = false;
                                return false;
                            }
                            return true;
                        }, T('Email已被占用, 请换一个, 或输入Gapper账号密码绑定账号'))
                        ->validate('password', function() use (&$nextVal, $form, $gapperRPC) {
                            if (!$nextVal) return true;
                            if (array_key_exists('password', $form) && $form['password'] !== '') {
                                $result = $gapperRPC->gapper->user->verify($form['email'], $form['password']);
                                if (!$result) return false;
                            }
                            return true;
                        }, T('Email和密码不匹配'));
                }

                $validator->done();

                $source = \Gini\Config::get('app.node');
                $bool = $gapperRPC->Gapper->User->LinkIdentity($email, $source, $identity);
                $user_id = $gapperRPC->Gapper->User->getInfo($email)['id'];
                if ($bool) {
                    if (!$gapperRPC->gapper->app->installTo($myClientID, 'user', $user_id)) {
                        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', T('您无权访问该应用，请联系系统管理员'));
                    }
                    \Gini\Gapper\Client::loginByUserName($email);
                }
                // 如果没有Gapper用户, 首先创建Gapper用户
                if (!\Gini\Gapper\Client::getUserName()) {
                    $uid = $gapperRPC->gapper->user->registerUserWithIdentity([
                        'username' => $email,
                        'password' => \Gini\Util::randPassword(),
                        'name' => $name,
                        'email' => $email,
                    ], $config->source, $identity);
                    if ($uid) {
                        if (!$gapperRPC->gapper->app->installTo($myClientID, 'user', $uid)) {
                            return \Gini\IoC::construct('\Gini\CGI\Response\JSON', T('您无权访问该应用，请联系系统管理员'));
                        }
                        \Gini\Gapper\Client::loginByUserName($email);
                    }

                    if (!\Gini\Gapper\Client::getUserName()) {
                        $validator->validate('*', false, T('用户注册失败，请重试!'))->done();
                    }
                }

                return \Gini\IoC::construct('\Gini\CGI\Response\JSON', true);
            }
            catch (\Exception $e) {
                $error = $validator->errors();
                if (empty($error)) {
                    $error['*'] = T('目前网络不稳定，建议您重新提交该表单');
                }
            }
        }

        $identityUser = $gapperRPC->gapper->user->getInfo($form['email']);
        if (!empty($identityUser)) {
            $hasGapper = true;
        }

        $vars = [
            'icon' => $config->icon,
            'type' => $config->name,
            'email' => $userInfo->email,
            'name' => $userInfo->name,
            'form'=> $form,
            'hasGapper' => $hasGapper,
            'error' => $error,
        ];

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', [
            'type'=> 'modal',
            'message'=> (string)V('gapper/auth/gateway/user-register-form', $vars)
        ]);
    }

    private function _hasInstall()
    {
        $apps = \Gini\Gapper\Client::getUserApps(\Gini\Gapper\Client::getUserName(), true);
        if (!$pps || !count($apps)) return false;
        $client_id = \Gini\Config::get('gapper.rpc')['client_id'];
        if (!isset($apps[$client_id])) return false;

        return true;
    }

    private function _getUserInfo($identity)
    {
        $rpc = \Gini\Module\AppBase::getGatewayRPC();
        return (object)$rpc->Gateway->People->getUser(['ref_no'=>$identity]);
    }

    private function _showError()
    {
        // unset($_SESSION['gapper-auth-gateway.username']);
        $view = (string)V(\Gini\Config::get('gapper.views')['client/error/401-user'] ?: 'gapper/client/error/401-user');
        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', [
            'type'=> 'modal',
            'message'=> $view
        ]);
    }
}
