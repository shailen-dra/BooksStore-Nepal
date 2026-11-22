<?php
/**
 * Nepal's districts.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal;

defined( 'ABSPATH' ) || exit;

/**
 * The 77 districts of Nepal, used as the checkout "state" for country NP.
 */
class Districts {

	public const VALLEY = array( 'NP-D-KATHMANDU', 'NP-D-LALITPUR', 'NP-D-BHAKTAPUR' );

	/**
	 * Code => English name, sorted by name.
	 */
	private const LIST = array(
		'NP-D-ACHHAM' => 'Achham',
		'NP-D-ARGHAKHANCHI' => 'Arghakhanchi',
		'NP-D-BAGLUNG' => 'Baglung',
		'NP-D-BAITADI' => 'Baitadi',
		'NP-D-BAJHANG' => 'Bajhang',
		'NP-D-BAJURA' => 'Bajura',
		'NP-D-BANKE' => 'Banke',
		'NP-D-BARA' => 'Bara',
		'NP-D-BARDIYA' => 'Bardiya',
		'NP-D-BHAKTAPUR' => 'Bhaktapur',
		'NP-D-BHOJPUR' => 'Bhojpur',
		'NP-D-CHITWAN' => 'Chitwan',
		'NP-D-DADELDHURA' => 'Dadeldhura',
		'NP-D-DAILEKH' => 'Dailekh',
		'NP-D-DANG' => 'Dang',
		'NP-D-DARCHULA' => 'Darchula',
		'NP-D-DHADING' => 'Dhading',
		'NP-D-DHANKUTA' => 'Dhankuta',
		'NP-D-DHANUSHA' => 'Dhanusha',
		'NP-D-DOLAKHA' => 'Dolakha',
		'NP-D-DOLPA' => 'Dolpa',
		'NP-D-DOTI' => 'Doti',
		'NP-D-GORKHA' => 'Gorkha',
		'NP-D-GULMI' => 'Gulmi',
		'NP-D-HUMLA' => 'Humla',
		'NP-D-ILAM' => 'Ilam',
		'NP-D-JAJARKOT' => 'Jajarkot',
		'NP-D-JHAPA' => 'Jhapa',
		'NP-D-JUMLA' => 'Jumla',
		'NP-D-KAILALI' => 'Kailali',
		'NP-D-KALIKOT' => 'Kalikot',
		'NP-D-KANCHANPUR' => 'Kanchanpur',
		'NP-D-KAPILVASTU' => 'Kapilvastu',
		'NP-D-KASKI' => 'Kaski',
		'NP-D-KATHMANDU' => 'Kathmandu',
		'NP-D-KAVREPALANCHOK' => 'Kavrepalanchok',
		'NP-D-KHOTANG' => 'Khotang',
		'NP-D-LALITPUR' => 'Lalitpur',
		'NP-D-LAMJUNG' => 'Lamjung',
		'NP-D-MAHOTTARI' => 'Mahottari',
		'NP-D-MAKWANPUR' => 'Makwanpur',
		'NP-D-MANANG' => 'Manang',
		'NP-D-MORANG' => 'Morang',
		'NP-D-MUGU' => 'Mugu',
		'NP-D-MUSTANG' => 'Mustang',
		'NP-D-MYAGDI' => 'Myagdi',
		'NP-D-NAWALPARASI-EAST' => 'Nawalparasi East (Nawalpur)',
		'NP-D-NAWALPARASI-WEST' => 'Nawalparasi West (Parasi)',
		'NP-D-NUWAKOT' => 'Nuwakot',
		'NP-D-OKHALDHUNGA' => 'Okhaldhunga',
		'NP-D-PALPA' => 'Palpa',
		'NP-D-PANCHTHAR' => 'Panchthar',
		'NP-D-PARBAT' => 'Parbat',
		'NP-D-PARSA' => 'Parsa',
		'NP-D-PYUTHAN' => 'Pyuthan',
		'NP-D-RAMECHHAP' => 'Ramechhap',
		'NP-D-RASUWA' => 'Rasuwa',
		'NP-D-RAUTAHAT' => 'Rautahat',
		'NP-D-ROLPA' => 'Rolpa',
		'NP-D-RUKUM-EAST' => 'Rukum East',
		'NP-D-RUKUM-WEST' => 'Rukum West',
		'NP-D-RUPANDEHI' => 'Rupandehi',
		'NP-D-SALYAN' => 'Salyan',
		'NP-D-SANKHUWASABHA' => 'Sankhuwasabha',
		'NP-D-SAPTARI' => 'Saptari',
		'NP-D-SARLAHI' => 'Sarlahi',
		'NP-D-SINDHULI' => 'Sindhuli',
		'NP-D-SINDHUPALCHOK' => 'Sindhupalchok',
		'NP-D-SIRAHA' => 'Siraha',
		'NP-D-SOLUKHUMBU' => 'Solukhumbu',
		'NP-D-SUNSARI' => 'Sunsari',
		'NP-D-SURKHET' => 'Surkhet',
		'NP-D-SYANGJA' => 'Syangja',
		'NP-D-TANAHUN' => 'Tanahun',
		'NP-D-TAPLEJUNG' => 'Taplejung',
		'NP-D-TERHATHUM' => 'Terhathum',
		'NP-D-UDAYAPUR' => 'Udayapur',
	);

	/**
	 * All districts.
	 *
	 * @return array<string,string> Code => name.
	 */
	public static function all(): array {
		return self::LIST;
	}
}
