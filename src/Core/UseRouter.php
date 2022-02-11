<?php

namespace Cdyun\PhpCrontab\Core;

/**
 * @method static UseRouter get(string $route, Callable $callback)
 * @method static UseRouter post(string $route, Callable $callback)
 * @method static UseRouter put(string $route, Callable $callback)
 * @method static UseRouter delete(string $route, Callable $callback)
 * @method static UseRouter options(string $route, Callable $callback)
 * @method static UseRouter head(string $route, Callable $callback)
 */
class UseRouter
{
    public static $routes = array();
    public static $methods = array();
    public static $callbacks = array();
    public static $error_callback;

    /**
     * Defines a route w/ callback and method
     * @param $method
     * @param $params
     */
    public static function __callStatic($method, $params)
    {
        $uri = strpos($params[0], '/') === 0 ? $params[0] : '/' . $params[0];
        $callback = $params[1];

        array_push(self::$routes, $uri);
        array_push(self::$methods, strtoupper($method));
        array_push(self::$callbacks, $callback);
    }

    /**
     * Defines callback if route is not found
     * @param $callback
     */
    public static function error(callable $callback)
    {
        self::$error_callback = $callback;
    }

    /**
     * 匹配路由
     * @param $method
     * @param $uri
     * @return false|mixed
     */
    public static function dispatch($method, $uri)
    {
        $beRoute = false;
        if (in_array($uri, self::$routes)) {
            $route_keys = array_keys(self::$routes, $uri);
            foreach ($route_keys as $key) {
                if (self::$methods[$key] == $method || self::$methods[$key] == 'ANY') $beRoute = true;
                if ($beRoute) return call_user_func(self::$callbacks[$key]);
            }
        }
        if ($beRoute == false) {
            if (!self::$error_callback) {
                self::$error_callback = function () {
                    return "404 Not Found";
                };
            }
            return call_user_func(self::$error_callback);
        }
        return '404';
    }
}
