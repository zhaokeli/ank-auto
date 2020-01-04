<?php
namespace controller\auto;

use ank\Controller;
use ank\Utils;

/**
 * 自动化生成工具
 */
class Index extends Controller
{
    public function greateTable()
    {
        $dbConfig = $this->app->config('db_config');
        $list     = $this->db->query('SELECT * FROM information_schema.TABLES  WHERE  TABLE_SCHEMA=\'' . $dbConfig['database_name'] . '\' ORDER BY TABLE_NAME ASC');
        // $tables       = array_column($list, 'table_name');
        $modelPath    = $this->app->getAppPath() . '/model';
        $validatePath = $this->app->getAppPath() . '/validate';
        if (!file_exists($modelPath)) {
            mkdir($modelPath, 777, true);
        }
        if (!file_exists($validatePath)) {
            mkdir($validatePath, 777, true);
        }
        foreach ($list as $key => $value) {
            $value = array_change_key_case($value);
            // $fields    = $this->db->table($tableName)->getFields();
            $srcTableName = $value['table_name'];
            $tableName    = Utils::parseName(ltrim($srcTableName, $dbConfig['prefix']), 1);
            $tableComment = $value['table_comment'];
            // $fields       = $this->db->query('DESC ' . $srcTableName);
            $fieldList = $this->db->query('SELECT * FROM information_schema.COLUMNS  WHERE  TABLE_SCHEMA=\'' . $dbConfig['database_name'] . '\' AND TABLE_NAME=\'' . $srcTableName . '\' ORDER BY COLUMN_NAME ASC');
            // var_dump($fields);
            // die();
            //判断类是否存在
            $filePath = $modelPath . '/' . $tableName . '.php';
            if (!class_exists('model\\' . $tableName)) {
                // $fields   = array_column($fieldList, 'COLUMN_NAME');
                $allField = [];
                $fieldMap = [];
                foreach ($fieldList as $key => $value) {
                    $value      = array_change_key_case($value);
                    $allField[] = "'{$value['column_name']}', " . $this->getFieldComment($value['column_comment']);
                    $fieldMap[] = "'{$value['column_name']}' => '{$value['column_name']}', " . $this->getFieldComment($value['column_comment']);
                }
                $fieldMap = $this->getFormatStr($fieldMap);
                $allField = $this->getFormatField($allField);
                $code     = <<<eot
<?php
namespace model;

use ank\\Model;

/**
 * TableName: {$tableName}
 * Comment: {$tableComment}
 * Auto Greate Model
 */
class {$tableName} extends Model
{
    /**
     * 自动添加的字段，如果是键值对,则值就是这个字段的值，
     * 如果只有一个字段则自动查找set[Field]Attr方法来设置这个字段
     * @var array
     */
    protected \$auto = [];

    //更新时处理

    /**
     * 添加下面这个方法是为啦方便,因为数据库可以自动过滤post数据添加到数据库,
     * 添加下面字段能让让模型类在发送到数据库前先自动删除不想让更新或添加的字段,
     * 比如客户编号,用户uid等字段，后期可以使用场景scene来完成
     * @var array
     */
    protected \$beforeInsertDelete = [];

    //添加时要删除的字段
    protected \$beforeUpdateDelete = [];

    // 查询字段,如果使用字段映射的话,请使用字段的别名
    protected \$field = [
        {$allField}
    ];

    protected \$fieldMap = [
        //格式为 别名(查询)字段=>数据库真实字段
        {$fieldMap}
    ];

    /**
     * 字段风格,把传入的字段转为下面
     * 0:默认字段，1:转换为下划线风格，2:转换为驼峰风格
     * @var null
     */
    protected \$fieldMode = 0;

    //添加更新时都会处理
    protected \$insert = [];

    //更新时要删除的字段

    protected \$join = [];

    protected \$limit = '';

    protected \$order = '';

    protected \$tableName = '{$tableName}';

    //添加时处理
    protected \$update = [];

    protected \$where = [];
}
eot;
                echo $filePath, PHP_EOL;
                file_put_contents($filePath, $code);
            } else {
                echo $filePath, ' => skip ', PHP_EOL;
            }

            $filePath = $validatePath . '/' . $tableName . '.php';
            if (!class_exists('validate\\' . $tableName)) {
                $valiField = [];
                $valiMsg   = [];
                foreach ($fieldList as $key => $value) {
                    $value = array_change_key_case($value);
                    //过滤掉主键
                    if ($value['column_key'] === 'PRI') {
                        continue;
                    }
                    $ruleInfo    = $this->getFieldRule($value);
                    $valiField[] = '\'' . $value['column_name'] . '\'=> \'' . $ruleInfo['rule'] . '\',' . $this->getFieldComment($value['column_comment']);
                    if (isset($ruleInfo['msg'])) {
                        $valiMsg = array_merge($valiMsg, $ruleInfo['msg']);
                    }
                }
                $valiField = $this->getFormatStr($valiField);
                $valiMsg   = $this->getFormatStr($valiMsg);
                $code      = <<<eot
<?php
namespace validate;

use ank\\validate;

/**
 * TableName: {$tableName}
 * Comment: {$tableComment}
 * Auto Greate Validate
 */
class {$tableName} extends Validate
{
    protected \$message = [
        {$valiMsg}
    ];

    protected \$rule = [
        {$valiField}
    ];

}
eot;
                echo $filePath, PHP_EOL;
                file_put_contents($filePath, $code);
            } else {
                echo $filePath, ' => skip ', PHP_EOL;
            }
        }
        echo 'greate table success';
    }

    private function getFieldComment($value)
    {
        if ($value) {
            return ' // ' . $value;
        }

        return '';

    }

    private function getFieldRule($value)
    {
        if ($value['is_nullable'] === 'NO') {
            if ($value['column_default'] === null) {
                return ['rule' => 'require', 'msg' => [
                    '\'' . $value['column_name'] . '.require\' => \'不能为空\',',
                ]];
            } elseif (in_array($value['data_type'], ['int', 'bigint', 'float', 'double', 'tinyint', 'smallint'])) {
                return ['rule' => 'number', 'msg' => [
                    '\'' . $value['column_name'] . '.number\' => \'数字格式不正确\',',
                ]];
            } elseif (in_array($value['data_type'], ['datetime', 'timestamp'])) {
                return ['rule' => 'require|date', 'msg' => [
                    '\'' . $value['column_name'] . '.require\' => \'不能为空\',',
                    '\'' . $value['column_name'] . '.date\' => \'日期格式不正确\',',
                ]];
            }

        }

        return ['rule' => ''];

    }

    private function getFormatField($arr)
    {
        $rmaxlen = 0;
        foreach ($arr as $key => $value) {
            $tem     = strlen(trim(explode('//', $value)[0]));
            $rmaxlen = $rmaxlen > $tem ? $rmaxlen : $tem;
        }
        $rearr = [];
        foreach ($arr as $key => $value) {
            $tt  = explode('//', $value);
            $tem = trim($tt[0]);
            $tem = str_pad($tem, $rmaxlen, ' ');
            if (isset($tt[1])) {
                $rearr[] = $tem . ' //' . $tt[1];
            } else {
                $rearr[] = trim($tem);
            }
        }

        return implode(PHP_EOL . str_repeat(' ', 8), $rearr);
    }

    private function getFormatStr($arr)
    {
        $maxlen  = 0;
        $rmaxlen = 0;
        foreach ($arr as $key => $value) {
            $tt     = explode('=>', $value);
            $tem    = strlen(trim($tt[0]));
            $maxlen = $maxlen > $tem ? $maxlen : $tem;

            $tem     = strlen(trim(explode('//', $tt[1])[0]));
            $rmaxlen = $rmaxlen > $tem ? $rmaxlen : $tem;
        }
        $rearr = [];
        foreach ($arr as $key => $value) {
            $tt   = explode('=>', $value);
            $tem  = trim($tt[0]);
            $tem  = str_pad($tem, $maxlen, ' ');
            $arr2 = explode('//', trim($tt[1]));
            if (isset($arr2[1])) {
                $rearr[] = $tem . ' => ' . str_pad(trim($arr2[0]), $rmaxlen, ' ') . ' //' . $arr2[1];
            } else {
                $rearr[] = $tem . ' => ' . trim($arr2[0]);
            }

        }

        return implode(PHP_EOL . str_repeat(' ', 8), $rearr);
    }
}
