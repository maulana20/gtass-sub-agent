<?php
include_once 'Startup.php';
require_once 'GTASSModel.php';
require_once 'PHPExcelModel.php';

function logRes($path, $result)
{
	$f = fopen($path, 'a');
	fwrite($f, date('Ymd H:i:s') . " " . $result . "\r\n");
	fclose($f);
}

function moveFile($file_name, $ext)
{
	shell_exec('echo Y| COPY file\\' . $file_name . ' file_ext\\' . rtrim($file_name, '.' . $ext) . date('YmdHis') . '.' . $ext . ' /Y');
	shell_exec('echo Y| DEL file\\' . $file_name . ' /Q');
}

// MASUKAN NAMA FILE NYA
echo "> Nama File : " . "\n";
echo "> ";
$handle = fopen ("php://stdin","r");
$file_name = trim(fgets($handle));
if (empty($file_name)) {
	echo "result : Nama file tidak boleh kosong !";
	logRes('log/gtass_log.txt', "Nama file tidak boleh kosong !");
	exit();
}

if (file_exists('file/' . $file_name)) {
	
	// HANYA FORMAT EXCEL AJA YANG BOLEH
	$ext = pathinfo($file_name, PATHINFO_EXTENSION);
	if ( !in_array($ext, array('xls','xlsx')) ) {
		echo "result : Format file tidak dapat di gunakan !";
		logRes('log/gtass_log.txt', "Format file tidak dapat di gunakan !");
		exit();
	}
	
	// OPEN EXCEL HARUS SUPPORT DENGAN FORMAT PHPEXCEL DARI LIBRARY
	try {
		$phpexcel = new PHPExcelModel($file_name);
	} catch (Exception $e) {
		echo $e->getMessage();
		exit();
	}
	
	// TAMPILKAN DATA UNTUK DI COCOKAN SEBAGAI ACUAN PENYESUAIAN DATA SEBELUM DI PROSES SEPERTI JUDUL DAN DATA ISINYA SESUAI
	$title_list = array();
	$title_list = $phpexcel->getTitle();
	if (empty($title_list)) {
		echo "result : Format tidak mendukung !";
		logRes('log/gtass_log.txt', "Format tidak mendukung !");
		exit();
	}
	if ($title_list[3] != 'Bank') {
		echo "result : Format title tidak mendukung !";
		logRes('log/gtass_log.txt', "Format title tidak mendukung !");
		exit();
	}
	echo implode('|', $title_list) . "\n";
	
	$list = array();
	$list = $phpexcel->getHistoryData();
	foreach ($list as $k => $v) {
		$record = array();
		foreach ($title_list as $key => $val) {
			$record[] = $v[$val];
		}
		echo implode('|', $record) . "\n";
	}
	
	// PERINTAH UNTUK PROSES
	echo "\n";
	echo "> Simpan data ? (Y/N)" . "\n";
	echo "> ";
	while (true) {
		// LANJUT MANG
		$handle = fopen ("php://stdin", "r");
		$cmd = strtoupper(trim(fgets($handle)));
		
		// TIDAK DI PROSES
		if ( in_array($cmd, array('QUIT', 'quit')) ) exit();
		if ( in_array($cmd, array('n', 'N')) ) {
			echo "result : Data tidak simpan !";
			logRes('log/gtass_log.txt', "Data tidak simpan !");
			break;
		}
		
		// PROSES DATA
		if ( in_array($cmd, array('y', 'Y')) ) {
			
			// MASUKAN KODE COA UNTUK BISA DI OLAH DI ARAHKAN KEMANA
			echo "> Masukan Kode Akun (COA)" . "\n";
			echo "> ";
			$handle = fopen ("php://stdin","r");
			$coa_code = trim(fgets($handle));
			if (empty($coa_code)) {
				echo "result : Kode akun kosong !";
				logRes('log/gtass_log.txt', "Kode akun kosong !");
				break;
			}
			if (strlen($coa_code) != 6) {
				echo "result : Kode akun harus sesuai (6 angka) !";
				logRes('log/gtass_log.txt', "Kode akun harus sesuai (6 angka) !");
				break;
			}
						
			// KIRIM DATA KE GTASS MULAI
			$gtass = new GTASSModel();
			$gtass->start($params);
			
			// CEK COA AKUNNYA ADA TIDAK
			$is_coa = true;
			$is_coa = $gtass->isCoa($coa_code);
			if (!$is_coa) {
				echo "result : Kode akun tidak ada !";
				logRes('log/gtass_log.txt', "Kode akun tidak ada !");
				$gtass->logoutClient();
				break;
			}
			
			foreach ($list as $k => $v) {
				// UNTUK RECORD BENTUKAN ARRAY SAYA SUPAYA TAMPIL DI LAYAR
				$record = array();
				foreach ($title_list as $key => $val) {
					$record[] = $v[$val];
				}
				
				// CEK DATA TERSEBUT UNTUK DI PILAH
				$result = NULL;
				if (! in_array($v['Flag'], array('Success', 'Manual')) ) {
					$result = implode('|', $record) . " Not Process (Only Flag Success or Manual)" . "\r\n";
					sleep(5);
				} else {
					if ($v['Credit'] == 0) { // hanya deposit agent saja yang bisa di proses
						$result = implode('|', $record) . " Not Process (Only Deposit Agent)" . "\r\n";
						sleep(5);
					} else {
						// PROSES MEMASUKAN DATA
						$is_already = true;
						$is_already = $gtass->isAlreadyDepAgent($v); // cek berdasar tanggal, sub agent versa, page size 1000, deskripsi, amount
						if (!$is_already) {
							$gtass->addDepositAgentCb($v, $coa_code); // masukan data berdasarkan kode akun, sub agent versa
							$result = implode('|', $record) . " Done" . "\r\n";
							sleep(10);
						} else {
							$result = implode('|', $record) . " Is Already" . "\r\n";
							sleep(5);
						}
					}
				}
				
				// TAMPILKAN PROSES
				echo $result;
				
				// BACKUP RESULT PROSES
				$f = fopen('file_ext/' . rtrim($file_name, '.' . $ext) . '.txt', 'a');
				fwrite($f, $result);
				fclose($f);
			}
			
			// PIDAHKAN FILE NYA JIKA SUDAH BERHASIL (PENANDA)
			moveFile($file_name, $ext);
			
			// BUAT TANDAIN AJA
			echo "result : Data proses !";
			logRes('log/gtass_log.txt', "Data proses !");
			$gtass->logoutClient();
			break;
		}
		echo "> ";
	}
} else {
	echo "result : File tidak di temukan !";
	logRes('log/gtass_log.txt', "File tidak di temukan !");
	exit();
}
