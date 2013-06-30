<?php 
include 'formatnum.php'; // FormatTelNum() Форматирование и геостатус номера
include 'localname.php';
include 'translit.php'; # Функция latrus()


// Считываем переданые параметры поиска и если их нет задаем дефолты
$date_to=$_GET["date_to"];
$date_from=$_GET["date_from"];
$str_find=$_GET["str_find"];
if ($_GET["str_limit"] == "" ) 
	{$str_limit="100";}
else	{$str_limit=$_GET["str_limit"];}

if(!isset($date_from)){$date_from=date("m/d/Y");}
if(!isset($date_to)){$date_to=date("m/d/Y");}

// Рисуем форму для поиска
echo "<table border='0'>";
echo "
<form method='get' action=''>

<! -- Добавляем скрипт календаря для удобства задания периода в форме поиска/--!>

 <link rel='stylesheet' type='text/css' href='cal/tcal.css' />
 <script type='text/javascript' src='cal/tcal.js'></script>

<td>	Дата начала: <input type='text' name='date_from' class='tcal' value='".$date_from."' SIZE=8> 
<td>	Дата окончания: <input type='text' name='date_to' class='tcal' value='".$date_to."' SIZE=8>
<td>	Поиск: <input type='text' name='str_find' value='".$str_find."'>
<td>	<input type='submit' value='Найти'>
</td>
</table>
</form>
<br>
";


// Ищем в файле конфигурации FreePBX логин и пароль к базе
$login=exec("grep AMPDBUSER /etc/amportal.conf|grep -v '^#'|tail -n 1|awk -F '=' '{print $2}'");
$password=exec("grep AMPDBPASS /etc/amportal.conf|grep -v '^#'|tail -n 1|awk -F '=' '{print $2}'");

mysql_connect("127.0.0.1", $login, $password) or die(mysql_error());
mysql_select_db("asteriskcdrdb") or die(mysql_error());


// Текст запроса к базе


$strdate="BETWEEN STR_TO_DATE('".$date_from." 00:00:00', '%m/%d/%Y %H:%i:%s') AND STR_TO_DATE('".$date_to." 23:59:59', '%m/%d/%Y %H:%i:%s')";


$strSQL = 
("


select 	t1.extension ext,
	LEFT(t1.name,LOCATE('-',t1.name)-1) pref,
	RIGHT(t1.name,LENGTH(t1.name)-LOCATE('-',t1.name)) name,

	t2.ocnt_all ish_vsego,
	t2.ocnt_answ ish_uspesh,
	t2.ocnt_int ish_vnutr,
	t2.ocnt_answ-t2.ocnt_int ish_vnesh,
	SEC_TO_TIME(otime_all) ish_vrem,
	SEC_TO_TIME(owait_all) ish_vozhid, 
	SEC_TO_TIME(obill_all) ish_vrazgv,
	SEC_TO_TIME(obill_int) ish_vraz_vnut,
	SEC_TO_TIME(obill_all-obill_int) ish_vraz_vnesh,
	SEC_TO_TIME((obill_all-obill_int)/(t2.ocnt_answ-t2.ocnt_int)) vsred_obzvon,

	icnt_all vh_vsego,
	icnt_all-icnt_answ vh_prop,
	icnt_answ vh_prnt,
	icnt_int vh_vnut,
	icnt_answ-icnt_int vh_vhesh,
	SEC_TO_TIME(itime_all) vh_vrem,
	SEC_TO_TIME(itime_all-ibill_all) vh_vozhid,
	SEC_TO_TIME(ibill_all) vh_vrazg,
	SEC_TO_TIME(ibill_int) vh_vvnutr,
        SEC_TO_TIME(ibill_all-ibill_int) vh_vvnesh,
	SEC_TO_TIME((ibill_all-ibill_int)/(icnt_answ-icnt_int)) vsred_vh_vnesh,

	SEC_TO_TIME(if(isnull(itime_all),0,itime_all)+if(isnull(otime_all),0,otime_all)) all_time
	
	
from


(select * from asterisk.users) as t1
left join
(select 	src,
		count(src)ocnt_all,
		sum(if(dst in(select extension from asterisk.users) and disposition = 'ANSWERED',1,0))ocnt_int,
		sum(if(disposition = 'ANSWERED',1,0))ocnt_answ,

		sum(duration)otime_all,
		sum(duration)-sum(billsec)owait_all,
		sum(billsec)obill_all,
		sum(if(dst in(select extension from asterisk.users),billsec,0))obill_int

	from cdr 
	where 
	calldate $strdate group by src) as t2
on t1.extension=t2.src
left join
(select         dst,
                count(src)icnt_all,
		sum(if(src in(select extension from asterisk.users) and disposition = 'ANSWERED',1,0))icnt_int,
		sum(if(disposition = 'ANSWERED',1,0)) icnt_answ,

		sum(duration)itime_all,
                sum(duration)-sum(billsec)iwait_all,
                sum(billsec)ibill_all,
		sum(if(src in(select extension from asterisk.users),billsec,0))ibill_int
        from cdr 
        where 
        calldate $strdate
	group by dst) as t3
on t1.extension=t3.dst
where not (isnull(t2.ocnt_all) and isnull(icnt_all)) and
CONCAT_WS('|',t1.extension,LEFT(t1.name,LOCATE('-',t1.name)-1),RIGHT(t1.name,LENGTH(t1.name)-LOCATE('-',t1.name))) like ('%".$str_find."%')
order by all_time desc


");
//echo $strSQL;

// Выполняем запрос
$rs = mysql_query($strSQL);
//	<th><div id='rotateText'>Номер</div>
//	<th><div id='rotateText'>Префикс</div>

echo "<table border='1'>";
echo "
<tr align='center'>
        <th>Номер
        <th>Префикс
	<th>Оператор
	<th>Исходящие<br>Внешние
	<th>Входящие<br>Внешние
	<th>Проп.
	<th>Общее время<br>на линии
</td>";


// Извлекаем значения и формируем таблицу результатов
while($id=mysql_fetch_row($rs))
	{ 
echo "<tr>" .
               "<td>" . $id[0] . 
               "<td>" . $id[1] . 
               "<td title=\"".$id[2]."\">" . latrus($id[2]) . 
               "<td align='center' title='Исходящих всего: ".$id[7]." / ".$id[3].
			" из них внутренних: ".$id[10]." / ".$id[5]."'>
			".$id[11]." - ".$id[6]. 

               "<td align='center' title='Входящих всего: ".$id[18]." / ".$id[13].
                        " из них внутренних: ".$id[21]." / ".$id[16]."'>
                        ".$id[22]." - ".$id[17].
               "<td align='center'>" . $id[14] . 
               "<td align='center'>" . $id[24] . 

               "</td>";

	}
echo "</td></table>";




?>


