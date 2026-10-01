<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BaganAkun;
use App\Models\TipePembebanan;
use App\Models\Supplier;
use App\Models\Bahan;
use App\Models\KategoriBahan;
use App\Models\Gudang;
use App\Models\AccountingSetting;
use App\Models\TaxRate;
use App\Models\KategoriAset;
use App\Models\KategoriJasa;
use App\Models\StokGudang;
use App\Models\MutasiStok;
use App\Models\User;
use App\Models\PengaturanBahanGudang;
use App\Models\LokasiGudang;
use App\Models\LotPersediaan;
use App\Services\DocumentNumberService;
use App\Services\ThreeWayMatchService;
use App\Models\FakturPembelian;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserRoleSeeder::class);
        $this->call(UserSeeder::class);

        // Konfigurasi master dan data transaksi simulasi dibuat otomatis agar
        // migrate:fresh --seed langsung menghasilkan lingkungan uji yang utuh.
        TaxRate::updateOrCreate(
            ['tax_type' => 'PPN', 'effective_from' => '2025-01-01'],
            ['rate' => 11, 'is_active' => true, 'description' => 'Default WMS; ubah melalui master tarif saat kebijakan berubah.']
        );
        TaxRate::updateOrCreate(
            ['tax_type' => 'PPH23', 'effective_from' => '2025-01-01'],
            ['rate' => 2, 'is_active' => true, 'description' => 'Snapshot dasar PPh supplier; pengakuan tetap saat pembayaran.']
        );
        TaxRate::updateOrCreate(
            ['tax_type' => 'PPH22', 'effective_from' => '2025-01-01'],
            ['rate' => 1.5, 'is_active' => true, 'description' => 'Tarif umum PPh 22 atas pembelian barang; sesuaikan per jenis barang bila diperlukan.']
        );
        TaxRate::updateOrCreate(
            ['tax_type' => 'PPH4A2', 'effective_from' => '2025-01-01'],
            ['rate' => 10, 'is_active' => true, 'description' => 'Contoh tarif PPh final 4(2) untuk sewa tanah/bangunan; sesuaikan per jenis transaksi final.']
        );
        $coas = [
            ['kode_akun' => '1101', 'nama_akun' => 'Kas Utama', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT', 'is_cash_bank' => true],
            ['kode_akun' => '1102', 'nama_akun' => 'Bank BCA', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT', 'is_cash_bank' => true],
            ['kode_akun' => '1103', 'nama_akun' => 'PPN Masukan', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '1104', 'nama_akun' => 'PPN Masukan Impor', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '1301', 'nama_akun' => 'Persediaan Bahan Baku', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '1401', 'nama_akun' => 'Uang Muka Supplier', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '2101', 'nama_akun' => 'Hutang Usaha (Supplier)', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '2102', 'nama_akun' => 'Hutang LPB Belum Ditagih', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '2103', 'nama_akun' => 'Hutang PPh 23', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '2105', 'nama_akun' => 'Hutang PPh 22', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '2106', 'nama_akun' => 'Hutang PPh 4(2) Final', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '5101', 'nama_akun' => 'Beban Bahan Baku Direct', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '5102', 'nama_akun' => 'Beban Perlengkapan Operasional', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '5103', 'nama_akun' => 'Beban Administrasi Bank', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '5104', 'nama_akun' => 'Beban Materai', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '4201', 'nama_akun' => 'Pendapatan Selisih Pembayaran', 'kategori_akun' => 'PENDAPATAN', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '5105', 'nama_akun' => 'Beban Angkut Pembelian', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '5201', 'nama_akun' => 'Diskon Pembelian', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '5106', 'nama_akun' => 'Beban Selisih Stock Opname', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '5107', 'nama_akun' => 'Beban Selisih Pembayaran', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '4202', 'nama_akun' => 'Koreksi Positif Stock Opname', 'kategori_akun' => 'PENDAPATAN', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '1302', 'nama_akun' => 'Barang Dalam Proses Jasa', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '1501', 'nama_akun' => 'Peralatan dan Inventaris', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '1591', 'nama_akun' => 'Akumulasi Penyusutan Peralatan', 'kategori_akun' => 'ASET', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '2104', 'nama_akun' => 'GRNI Jasa', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '2201', 'nama_akun' => 'Hutang Pembelian Asset', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '3102', 'nama_akun' => 'Ekuitas Saldo Awal Asset', 'kategori_akun' => 'EKUITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '4203', 'nama_akun' => 'Keuntungan Pelepasan Asset', 'kategori_akun' => 'PENDAPATAN', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '5202', 'nama_akun' => 'Beban Jasa Operasional', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '5301', 'nama_akun' => 'Beban Penyusutan Peralatan', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT', 'klasifikasi_fiskal' => BaganAkun::FISKAL_BEDA_WAKTU],
            ['kode_akun' => '5302', 'nama_akun' => 'Kerugian Pelepasan Asset', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '4204', 'nama_akun' => 'Laba Selisih Kurs', 'kategori_akun' => 'PENDAPATAN', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '5303', 'nama_akun' => 'Rugi Selisih Kurs', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '5304', 'nama_akun' => 'Beban Sanksi dan Denda Pajak', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT', 'klasifikasi_fiskal' => BaganAkun::FISKAL_BEDA_TETAP],
            ['kode_akun' => '5305', 'nama_akun' => 'Beban Sumbangan dan Natura', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT', 'klasifikasi_fiskal' => BaganAkun::FISKAL_BEDA_TETAP],
            ['kode_akun' => '4205', 'nama_akun' => 'Penghasilan Kena PPh Final', 'kategori_akun' => 'PENDAPATAN', 'posisi_normal' => 'KREDIT', 'klasifikasi_fiskal' => BaganAkun::FISKAL_PENGHASILAN_FINAL],
            ['kode_akun' => '2107', 'nama_akun' => 'Hutang PPh Badan', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '5306', 'nama_akun' => 'Beban PPh Badan', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT', 'klasifikasi_fiskal' => BaganAkun::FISKAL_BEDA_TETAP],
            ['kode_akun' => '1601', 'nama_akun' => 'Aset Pajak Tangguhan', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '2108', 'nama_akun' => 'Liabilitas Pajak Tangguhan', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '5307', 'nama_akun' => 'Beban Pajak Tangguhan', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT', 'klasifikasi_fiskal' => BaganAkun::FISKAL_BEDA_TETAP],
            ['kode_akun' => '5308', 'nama_akun' => 'Kerugian Pembatalan Produksi', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '2109', 'nama_akun' => 'Hutang PPN Keluaran', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '1201', 'nama_akun' => 'Piutang Usaha', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '4101', 'nama_akun' => 'Penjualan', 'kategori_akun' => 'PENDAPATAN', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '4102', 'nama_akun' => 'Retur Penjualan', 'kategori_akun' => 'PENDAPATAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '5401', 'nama_akun' => 'Beban Pokok Penjualan', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '1303', 'nama_akun' => 'Barang Dalam Proses Produksi', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '1304', 'nama_akun' => 'Persediaan Dalam Perjalanan', 'kategori_akun' => 'ASET', 'posisi_normal' => 'DEBIT'],
            ['kode_akun' => '2111', 'nama_akun' => 'Biaya Masih Harus Dibayar', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '2112', 'nama_akun' => 'Uang Muka Pelanggan', 'kategori_akun' => 'LIABILITAS', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '5203', 'nama_akun' => 'Beban Tenaga Kerja Langsung Diserap', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'KREDIT'],
            ['kode_akun' => '5204', 'nama_akun' => 'Beban Overhead Pabrik Diserap', 'kategori_akun' => 'BEBAN', 'posisi_normal' => 'KREDIT'],
        ];

        foreach ($coas as $coa) {
            BaganAkun::updateOrCreate(['kode_akun' => $coa['kode_akun']], array_merge([
                'is_active' => true,
                'is_postable' => true,
                'is_cash_bank' => false,
                'klasifikasi_fiskal' => BaganAkun::FISKAL_NONE,
            ], $coa));
        }

        $accountMappings = [
            AccountingSetting::HUTANG_USAHA => '2101',
            AccountingSetting::PPN_MASUKAN => '1103',
            AccountingSetting::PPN_IMPOR => '1104',
            AccountingSetting::HUTANG_PPH23 => '2103',
            AccountingSetting::HUTANG_PPH22 => '2105',
            AccountingSetting::HUTANG_PPH4A2 => '2106',
            AccountingSetting::BIAYA_BANK => '5103',
            AccountingSetting::BEBAN_MATERAI => '5104',
            AccountingSetting::SELISIH_BAYAR => '4201',
            AccountingSetting::BIAYA_ONGKIR => '5105',
            AccountingSetting::DISKON_PEMBELIAN => '5201',
            AccountingSetting::UANG_MUKA_SUPPLIER => '1401',
            AccountingSetting::HUTANG_PPH_BADAN => '2107',
            AccountingSetting::BEBAN_PPH_BADAN => '5306',
            AccountingSetting::ASET_PAJAK_TANGGUHAN => '1601',
            AccountingSetting::LIABILITAS_PAJAK_TANGGUHAN => '2108',
            AccountingSetting::BEBAN_PAJAK_TANGGUHAN => '5307',
            AccountingSetting::PPN_KELUARAN => '2109',
            AccountingSetting::PIUTANG_USAHA => '1201',
            AccountingSetting::PENJUALAN => '4101',
            AccountingSetting::RETUR_PENJUALAN => '4102',
            AccountingSetting::BEBAN_POKOK_PENJUALAN => '5401',
            AccountingSetting::BARANG_DALAM_PROSES => '1303',
            AccountingSetting::PERSEDIAAN_DALAM_PERJALANAN => '1304',
            AccountingSetting::BIAYA_MASIH_HARUS_DIBAYAR => '2111',
            AccountingSetting::UANG_MUKA_PELANGGAN => '2112',
            AccountingSetting::BEBAN_TENAGA_KERJA_DISERAP => '5203',
            AccountingSetting::BEBAN_OVERHEAD_DISERAP => '5204',
            AccountingSetting::RUGI_PEMBATALAN_PRODUKSI => '5308',
            AccountingSetting::LABA_SELISIH_KURS => '4204',
            AccountingSetting::RUGI_SELISIH_KURS => '5303',
        ];
        foreach ($accountMappings as $key => $code) {
            AccountingSetting::updateOrCreate(
                ['key' => $key],
                [
                    'coa_id' => BaganAkun::where('kode_akun', $code)->value('id'),
                    'description' => 'Mapping sistem WMS; nama dan kode akun tetap dapat disesuaikan accountant.'
                ]
            );
        }

        $tipe1 = TipePembebanan::updateOrCreate(
            ['nama_tipe' => 'DIRECT_COST'],
            ['keterangan' => 'Pembebanan langsung ke Beban Baku']
        );

        $tipe2 = TipePembebanan::updateOrCreate(
            ['nama_tipe' => 'INVENTORY'],
            ['keterangan' => 'Pembebanan ke Persediaan Stok Gudang']
        );

        $coaPersediaan = BaganAkun::where('kode_akun', '1301')->first();
        $coaBeban = BaganAkun::where('kode_akun', '5101')->first();
        $coaClearing = BaganAkun::where('kode_akun', '2102')->first();
        $coaOpnameLoss = BaganAkun::where('kode_akun', '5106')->first();
        $coaOpnameGain = BaganAkun::where('kode_akun', '4202')->first();

        $katBahan = KategoriBahan::updateOrCreate(
            ['katnama' => 'Bahan Baku Paper'],
            [
                'tipe_pembebanan_id'  => $tipe2->id,
                'coa_persediaan_id'   => $coaPersediaan?->id,
                'coa_beban_id'        => $coaBeban?->id,
                'coa_clearing_lpb_id' => $coaClearing?->id,
                'coa_beban_selisih_opname_id' => $coaOpnameLoss?->id,
                'coa_koreksi_opname_id' => $coaOpnameGain?->id,
            ]
        );

        $katJasaOperasional = KategoriBahan::updateOrCreate(
            ['katnama' => 'Jasa Operasional'],
            [
                'tipe_pembebanan_id'  => $tipe1->id,
                'coa_persediaan_id'   => BaganAkun::where('kode_akun', '5202')->value('id'),
                'coa_beban_id'        => BaganAkun::where('kode_akun', '5202')->value('id'),
                'coa_clearing_lpb_id' => BaganAkun::where('kode_akun', '2104')->value('id'),
                'coa_beban_selisih_opname_id' => $coaOpnameLoss?->id,
                'coa_koreksi_opname_id' => $coaOpnameGain?->id,
            ]
        );

        $katJasaProduksi = KategoriBahan::updateOrCreate(
            ['katnama' => 'Jasa Produksi'],
            [
                'tipe_pembebanan_id'  => $tipe2->id,
                'coa_persediaan_id'   => BaganAkun::where('kode_akun', '1302')->value('id'),
                'coa_beban_id'        => BaganAkun::where('kode_akun', '5202')->value('id'),
                'coa_clearing_lpb_id' => BaganAkun::where('kode_akun', '2104')->value('id'),
                'coa_beban_selisih_opname_id' => $coaOpnameLoss?->id,
                'coa_koreksi_opname_id' => $coaOpnameGain?->id,
            ]
        );

        $gudang = Gudang::updateOrCreate(['kode' => 'GDG-UTAMA'], ['nama' => 'Gudang Utama', 'jenis' => Gudang::NORMAL, 'aktif' => true, 'boleh_penerimaan' => true, 'boleh_npk' => true, 'boleh_transfer' => true, 'boleh_opname' => true]);
        $gudangProduksi = Gudang::updateOrCreate(['kode' => 'GDG-PRODUKSI'], ['nama' => 'Gudang Produksi', 'jenis' => Gudang::NORMAL, 'aktif' => true, 'boleh_penerimaan' => false, 'boleh_npk' => true, 'boleh_transfer' => true, 'boleh_opname' => true]);
        Gudang::updateOrCreate(['kode' => 'GDG-CONSIDER'], ['nama' => 'Gudang Consider', 'jenis' => Gudang::CONSIDER, 'aktif' => true, 'boleh_penerimaan' => false, 'boleh_npk' => false, 'boleh_transfer' => true, 'boleh_opname' => true]);
        Gudang::updateOrCreate(['kode' => 'GDG-RUSAK'], ['nama' => 'Gudang Rusak', 'jenis' => Gudang::RUSAK, 'aktif' => true, 'boleh_penerimaan' => false, 'boleh_npk' => false, 'boleh_transfer' => false, 'boleh_opname' => true]);
        Gudang::updateOrCreate(['kode' => 'GDG-SUBKONTRAK'], ['nama' => 'Gudang Subkontrak', 'jenis' => Gudang::NORMAL, 'aktif' => true, 'boleh_penerimaan' => true, 'boleh_npk' => false, 'boleh_transfer' => true, 'boleh_opname' => true]);

        $suppliers = [
            [
                'nama'       => 'PT. Jaya Mandiri Utama',
                'alamat'     => 'Jl. Industri No. 45, Jakarta',
                'telp'       => '021-5551234',
                'pembayaran' => 'CASH',
            ],
            [
                'nama'       => 'CV. Sumber Makmur',
                'alamat'     => 'Jl. Raya Banten No. 88, Tangerang',
                'telp'       => '021-5555678',
                'pembayaran' => 'KREDIT 30 HARI',
            ],
            [
                'nama'       => 'PT. Global Supply Indonesia',
                'alamat'     => 'Jl. Gatot Subroto Km 7, Surabaya',
                'telp'       => '031-7778899',
                'pembayaran' => 'KREDIT 14 HARI',
            ],
        ];

        foreach ($suppliers as $sup) {
            Supplier::updateOrCreate(['nama' => $sup['nama']], $sup);
        }

        $bahans = [
            [
                'kategori'         => $katBahan->id,
                'nama'             => 'Kertas Karton Dupleks 350gr',
                'keterangan_bahan' => 'Bahan Baku Kertas',
                'satuan'           => 'KG',
                'stok_onhand'      => 0,
                'stok_onpurchase'  => 0,
                'planning'         => 0,
                'stokawal'         => 0,
                'tipe_gudang'      => $gudang->id,
                'tipe_barang'      => $katBahan->id,
            ],
            [
                'kategori'         => $katBahan->id,
                'nama'             => 'Tinta Cetak Hitam Offset',
                'keterangan_bahan' => 'Bahan Penolong',
                'satuan'           => 'KG',
                'stok_onhand'      => 0,
                'stok_onpurchase'  => 0,
                'planning'         => 0,
                'stokawal'         => 0,
                'tipe_gudang'      => $gudang->id,
                'tipe_barang'      => $katBahan->id,
            ],
        ];

        foreach ($bahans as $bhn) {
            Bahan::updateOrCreate(['nama' => $bhn['nama']], $bhn);
        }

        KategoriAset::updateOrCreate(['code' => 'EQUIPMENT'], [
            'name' => 'Peralatan dan Inventaris',
            'akun_aset_id' => BaganAkun::where('kode_akun', '1501')->value('id'),
            'accumulated_depreciation_coa_id' => BaganAkun::where('kode_akun', '1591')->value('id'),
            'depreciation_expense_coa_id' => BaganAkun::where('kode_akun', '5301')->value('id'),
            'disposal_gain_coa_id' => BaganAkun::where('kode_akun', '4203')->value('id'),
            'disposal_loss_coa_id' => BaganAkun::where('kode_akun', '5302')->value('id'),
            'is_active' => true,
        ]);

        KategoriJasa::updateOrCreate(['code' => KategoriJasa::OPERATIONAL], [
            'display_code' => '98',
            'kategori_bahan_id' => $katJasaOperasional->id,
            'name' => 'Jasa Operasional',
            'expense_coa_id' => BaganAkun::where('kode_akun', '5202')->value('id'),
            'grni_coa_id' => BaganAkun::where('kode_akun', '2104')->value('id'),
            'requires_datapesanan' => false,
            'requires_cost_center' => true,
            'is_active' => true,
        ]);
        KategoriJasa::updateOrCreate(['code' => KategoriJasa::PRODUCTION], [
            'display_code' => '99',
            'kategori_bahan_id' => $katJasaProduksi->id,
            'name' => 'Jasa Produksi',
            'expense_coa_id' => BaganAkun::where('kode_akun', '1302')->value('id'),
            'grni_coa_id' => BaganAkun::where('kode_akun', '2104')->value('id'),
            'requires_datapesanan' => true,
            'requires_cost_center' => false,
            'is_active' => true,
        ]);

        $this->call([
            WmsDemoSeeder::class,
            WmsTransactionScenarioSeeder::class,
            AssetAndServiceDemoSeeder::class,
            FinancialStatementDemoSeeder::class,
        ]);

        $accountingUser = User::where('type', User::ROLE_ACCOUNTING)->first();
        if ($accountingUser) {
            Auth::setUser($accountingUser);
            foreach (FakturPembelian::where('status', '!=', FakturPembelian::VOID)->get() as $invoice) {
                app(ThreeWayMatchService::class)->evaluate($invoice);
            }
        }

        $this->syncMultiWarehouseDemoData();

        $this->call(PenjualanDanProduksiDemoSeeder::class);
        $this->call(OperasiGudangDemoSeeder::class);
        $this->call(EksekusiGudangDemoSeeder::class);
        $this->call(KontrolMutuDanPajakDemoSeeder::class);
    }

    private function syncMultiWarehouseDemoData(): void
    {
        $layerBalances = DB::table('wms_layer_persediaan')
            ->select('gudang_id', 'bahan_id', DB::raw('SUM(remaining_quantity) as quantity'))
            ->whereNotNull('gudang_id')->groupBy('gudang_id', 'bahan_id')->get();

        foreach ($layerBalances as $row) {
            StokGudang::updateOrCreate(
                ['gudang_id' => $row->gudang_id, 'bahan_id' => $row->bahan_id],
                ['stok_tersedia' => $row->quantity]
            );
        }

        $ordered = DB::table('wms_pesanan_pembelian_detail as d')->join('wms_pesanan_pembelian as p', 'p.no_po', '=', 'd.no_po')
            ->select('p.gudang_id', 'd.bahan_id', DB::raw('SUM(GREATEST(d.jumlah - d.diterima, 0)) as quantity'))
            ->where('p.status', \App\Models\PesananPembelian::OPEN)->whereNotNull('p.gudang_id')->groupBy('p.gudang_id', 'd.bahan_id')->get();
        foreach ($ordered as $row) {
            StokGudang::updateOrCreate(
                ['gudang_id' => $row->gudang_id, 'bahan_id' => $row->bahan_id],
                ['stok_dipesan' => $row->quantity]
            );
        }

        $warehouseUser = User::where('type', User::ROLE_WAREHOUSE)->first();
        $productionUser = User::where('type', User::ROLE_PRODUCTION)->first();
        foreach (Gudang::all() as $gudang) {
            if ($warehouseUser) {
                DB::table('pembagian_gudangs')->updateOrInsert(
                    ['user_id' => $warehouseUser->id, 'gudang_id' => $gudang->id],
                    ['boleh_menerima' => true, 'boleh_npk' => true, 'boleh_transfer' => true, 'boleh_opname' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        if ($productionUser) {
            $gudangProduksi = Gudang::where('kode', 'GDG-PRODUKSI')->first();

            if ($gudangProduksi) {
                DB::table('pembagian_gudangs')->updateOrInsert(
                    ['user_id' => $productionUser->id, 'gudang_id' => $gudangProduksi->id],
                    ['boleh_menerima' => false, 'boleh_npk' => true, 'boleh_transfer' => true, 'boleh_opname' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        foreach (StokGudang::where('stok_tersedia', '>', 0)->get() as $stock) {
            if (!MutasiStok::where('gudang_id', $stock->gudang_id)->where('bahan_id', $stock->bahan_id)->exists()) {
                MutasiStok::create([
                    'nomor_mutasi' => app(DocumentNumberService::class)->internal('MTS', 'STK'),
                    'tanggal' => now(), 'jenis_mutasi' => 'SALDO_AWAL', 'gudang_id' => $stock->gudang_id,
                    'bahan_id' => $stock->bahan_id, 'jumlah_masuk' => $stock->stok_tersedia, 'jumlah_keluar' => 0,
                    'saldo_sebelum' => 0, 'saldo_setelah' => $stock->stok_tersedia, 'harga_satuan' => 0,
                    'total_nilai' => 0, 'jenis_referensi' => 'SEEDER', 'referensi_id' => $stock->id,
                    'user_id' => $warehouseUser?->id, 'keterangan' => 'Saldo awal multi-warehouse dari data demo',
                ]);
            }
        }

        foreach (Gudang::where('aktif', true)->get() as $warehouse) {
            foreach ([
                ['code' => 'RCV-01', 'name' => 'Receiving Area', 'type' => 'RECEIVING'],
                ['code' => 'QC-01', 'name' => 'Quality Hold', 'type' => 'QC'],
                ['code' => 'STG-A-01', 'name' => 'Storage A-01', 'type' => 'STORAGE'],
            ] as $location) {
                LokasiGudang::updateOrCreate(['gudang_id' => $warehouse->id, 'code' => $location['code']], $location + ['active' => true]);
            }
        }

        $planningWarehouse = Gudang::where('kode', 'GDG-UTAMA')->firstOrFail();
        foreach (Bahan::all() as $material) {
            PengaturanBahanGudang::updateOrCreate(
                ['gudang_id' => $planningWarehouse->id, 'bahan_id' => $material->id],
                ['stok_minimum' => 5, 'stok_maksimum' => 30, 'stok_pengaman' => 3, 'titik_pemesanan' => 10, 'aktif' => true]
            );
        }

        foreach (DB::table('wms_layer_persediaan')->where('remaining_quantity', '>', 0)->get() as $layer) {
            $lot = LotPersediaan::updateOrCreate(
                ['bahan_id' => $layer->bahan_id, 'lot_number' => 'DEMO-' . str_pad((string) $layer->id, 4, '0', STR_PAD_LEFT)],
                ['quality_status' => 'RELEASED', 'manufactured_at' => today()->subMonth(), 'expires_at' => today()->addYear()]
            );
            DB::table('wms_layer_persediaan')->where('id', $layer->id)->update([
                'inventory_lot_id' => $lot->id,
                'warehouse_location_id' => LokasiGudang::where('gudang_id', $layer->gudang_id)->where('type', 'STORAGE')->value('id'),
            ]);
        }
    }
}
