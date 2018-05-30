<?php

namespace yeszao\cache;

use \InvalidArgumentException;
use \Redis;

/**
 * PHP轻量级Redis缓存策略类。
 *
 * @author 歪麦 <galley.meng@gmail.com>
 * @link https://www.awaimai.com/
 *
 */
class Cache
{
    /**
     * Redis句柄
     * @var Redis
     */
    private $redis;
    /**
     * 正在调用的方法名称
     * @var string
     */
    private $class;
    /**
     * 配置
     * @var array
     */
    private $config = [
        'prefix' => '',
        'expire' => 3600,       // 缓存过期时间
        'emptyExpire' => 10     // 空值的缓存过期时间
    ];
    /**
     * 可用的Redis操作方法名
     * @var array
     */
    private $actions = ['cache', 'clear', 'flush'];

    /**
     * 构造方法
     * @param Redis|null $redis
     */
    public function __construct(Redis $redis = null, $config = [])
    {
        $this->redis = $redis;
        $this->config = array_merge($this->config, $config);
    }

    /**
     * 设置缓存时间
     *
     * @param $time int 单位秒
     */
    public function expire($time)
    {
        $this->config['expire'] = $time;
    }

    /**
     * 处理缓存方法
     *
     * @param $object object 对象
     * @param $method string 方法名
     * @param $arguments array 参数列表
     * @return mixed
     * @throws InvalidArgumentException 如果方法不存在，抛出异常
     */
    public function get($object, $name, $arguments)
    {
        if ($this->redis === null) {
            throw new InvalidArgumentException("未初始化Redis变量。\n");
        }

        $this->class = get_class($object);

        if (strlen($name) < 5) {
            throw new InvalidArgumentException(sprintf("Method %s->%s does not exist", $this->class, $name));
        }

        $method = substr($name, 0, -5);
        $action = substr($name, -5);
        if (!in_array($action, $this->actions, true) === false) {
            throw new InvalidArgumentException(sprintf("Method %s->%s does not exist", $this->class, $method));
        }

        return $this->$action($object, $method, $arguments);
    }

    /**
     * 获取某个方法返回的数据。
     * 如果数据已经缓存，则直接读取缓存数据；
     * 如果数据未缓存，则调用实际方法获取数据。
     * 实际使用时，请在子类的注释加上`@method`注释，以便编辑器能够自动识别。
     * @return mixed
     * @throws InvalidArgumentException 如果方法不存在，抛出异常
     */
    private function cache($object, $method, $arguments)
    {
        $key = $this->key($method, $arguments);

        $data = $this->redis->get($key);
        if ($data !== false) {
            $decodeData = json_decode($data, JSON_UNESCAPED_UNICODE);
            return $decodeData === null ? $data : $decodeData;
        }

        if (method_exists($object, $method) === false) {
            throw new InvalidArgumentException(sprintf("Method %s->%s does not exist", $this->class, $method));
        }

        $data = call_user_func_array([$object, $method], $arguments);

        $expire = empty($data) ? $this->config['emptyExpire'] : $this->config['expire'];
        $this->redis->set($key, json_encode($data), $expire);

        return $data;
    }

    /**
     * 删除指定缓存，参数和原数据获取方法一样
     * @return mixed
     */
    private function clear($object, $method, $arguments)
    {
        return $this->redis->del($this->key($method, $arguments));
    }

    /**
     * 删除指定方法的所有缓存
     * @return bool
     */
    private function flush($object, $method, $arguments)
    {
        $key = $this->key($method, '*');
        $keys = $this->redis->keys($key);

        return $this->redis->del($keys);
    }

    /**
     * 生成缓存键名
     * @return string
     */
    private function key($method, $arguments)
    {
        $class = str_replace('\\', '_', $this->class);
        $args = ($arguments === '*') ? '*' : md5(json_encode($arguments));

        return strtolower(sprintf('%s%s:%s:%s', $this->config['prefix'], $class, $method, $args));
    }
}
