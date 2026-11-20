<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\BuktiPotong;
use App\Models\Jurnal;
use App\Models\KodeObjekPajak;
use App\Models\PembayaranFaktur;
use App\Models\User;
use App\Support\IdentitasPajak;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BuktiPotongService
{
    private array $kodeCache = [];

    private function kodeAktif(string $kode): ?KodeObjekPajak
    {
        if (!array_key_exists($kode, $this->kodeCache)) {
            $this->kodeCache[$kode] = KodeObjekPajak::aktif()->where('kode', $kode)->first();
        }

        return $this->kodeCache[$kode];
    }

    public function rentang(string $masa): array
    {
        $awal = Carbon::createFromFormat('Y-m-d', $masa . '-01')->startOfDay();

        return [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()];
    }

    public function pembayaranBerpph(string $masa): Collection
    {
        [$awal, $akhir] = $this->rentang($masa);

        return PembayaranFaktur::query()
            ->with(['invoice.supplier' => fn ($query) => $query->withTrashed()])
            ->where('status', PembayaranFaktur::POSTED)
            ->where('potongan_pph', '>', 0)
            ->whereBetween('tanggal_pembayaran', [$awal, $akhir])
            ->whereHas('invoice', fn ($query) => $query->whereIn('jenis_pph', BuktiPotong::JENIS_DIDUKUNG))
            ->orderBy('tanggal_pembayaran')
            ->orderBy('id')
            ->get();
    }

    public function sinkron(string $masa, ?User $oleh = null): array
    {
        [$awal, $akhir] = $this->rentang($masa);
        $hasil = ['dibuat' => 0, 'diperbarui' => 0, 'dibatalkan' => 0, 'ditandai' => 0];

        foreach ($this->pembayaranBerpph($masa) as $pembayaran) {
            $akibat = $this->sinkronPembayaran($pembayaran, $oleh);

            if ($akibat !== null) {
                $hasil[$akibat]++;
            }
        }

        $bukti = BuktiPotong::with('pembayaran')
            ->whereBetween('tanggal_pemotongan', [$awal, $akhir])
            ->whereIn('status', [BuktiPotong::DRAFT, BuktiPotong::DITERBITKAN])
            ->get();

        foreach ($bukti as $baris) {
            if ($baris->pembayaran?->status !== PembayaranFaktur::VOID) {
                continue;
            }

            if ($baris->isDraft()) {
                $baris->update([
                    'status' => BuktiPotong::DIBATALKAN,
                    'catatan' => 'Pembayaran sumber dibatalkan (VOID).',
                    'dibatalkan_pada' => now(),
                ]);
                $hasil['dibatalkan']++;
            } elseif (!$baris->perlu_pembatalan) {
                $baris->update([
                    'perlu_pembatalan' => true,
                    'catatan' => 'Pembayaran sumber dibatalkan (VOID); bukti terbit perlu dibatalkan di Coretax dan di sini.',
                ]);
                $hasil['ditandai']++;
            }
        }

        return $hasil;
    }

    private function sinkronPembayaran(PembayaranFaktur $pembayaran, ?User $oleh): ?string
    {
        return DB::transaction(function () use ($pembayaran, $oleh) {
            $terakhir = BuktiPotong::where('pembayaran_id', $pembayaran->id)
                ->where('urutan', 1)
                ->orderByDesc('versi')
                ->lockForUpdate()
                ->first();

            $data = $this->dataDariPembayaran($pembayaran);

            if ($terakhir === null) {
                try {
                    BuktiPotong::create($data + [
                        'pembayaran_id' => $pembayaran->id,
                        'urutan' => 1,
                        'versi' => 1,
                        'status' => BuktiPotong::DRAFT,
                        'dibuat_oleh' => $oleh?->id,
                    ]);
                } catch (QueryException $e) {
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }

                    return null;
                }

                return 'dibuat';
            }

            if ($terakhir->isDraft()) {
                $terakhir->fill($data);

                if ($terakhir->isDirty()) {
                    $terakhir->save();

                    return 'diperbarui';
                }
            }

            return null;
        });
    }

    private function dataDariPembayaran(PembayaranFaktur $pembayaran): array
    {
        $faktur = $pembayaran->invoice;
        $supplier = $faktur->supplier;
        $tanggal = Carbon::parse($pembayaran->tanggal_pembayaran);
        $tarif = (float) $faktur->tarif_pph;
        $potongan = round((float) $pembayaran->potongan_pph, 2);
        $dasar = $tarif > 0 ? round($potongan / ($tarif / 100), 2) : round((float) $faktur->dasar_pph, 2);

        return [
            'supplier_id' => $faktur->kode_supplier,
            'jenis_pph' => $faktur->jenis_pph,
            'kode_objek_pajak' => $faktur->kode_objek_pajak,
            'masa_pajak' => (int) $tanggal->format('n'),
            'tahun_pajak' => (int) $tanggal->format('Y'),
            'tanggal_pemotongan' => $tanggal->toDateString(),
            'dasar_pengenaan' => number_format($dasar, 2, '.', ''),
            'tarif' => number_format($tarif, 4, '.', ''),
            'jumlah_pph' => number_format($potongan, 2, '.', ''),
            'penerima_nama' => $supplier?->nama ?? '-',
            'penerima_npwp' => IdentitasPajak::normalisasiNpwp($supplier?->npwp),
            'penerima_nik' => IdentitasPajak::bersihkan($supplier?->nik),
            'penerima_nitku' => IdentitasPajak::bersihkan($supplier?->nitku),
            'penerima_alamat' => $supplier?->alamat ? mb_substr($supplier->alamat, 0, 300) : null,
        ];
    }

    public function masalahPenerbitan(BuktiPotong $bukti): array
    {
        $masalah = [];

        if (!in_array($bukti->jenis_pph, BuktiPotong::JENIS_DIDUKUNG, true)) {
            $masalah[] = 'Jenis PPh ' . $bukti->jenis_pph . ' belum didukung untuk bukti potong.';
        }

        $masalah = array_merge($masalah, $this->masalahIdentitas($bukti));

        if (blank($bukti->kode_objek_pajak)) {
            $masalah[] = 'Kode objek pajak belum diisi pada faktur pembelian sumber.';
        } else {
            $kode = $this->kodeAktif($bukti->kode_objek_pajak);

            if (!$kode) {
                $masalah[] = 'Kode objek pajak ' . $bukti->kode_objek_pajak . ' tidak dikenal atau tidak aktif.';
            } elseif ($kode->jenis_pph !== $bukti->jenis_pph) {
                $masalah[] = 'Kode objek pajak ' . $bukti->kode_objek_pajak . ' adalah milik ' . $kode->jenis_pph . ', bukan ' . $bukti->jenis_pph . '.';
            }
        }

        if ((float) $bukti->jumlah_pph <= 0 || (float) $bukti->dasar_pengenaan <= 0) {
            $masalah[] = 'Dasar pengenaan dan jumlah PPh harus lebih dari nol.';
        }

        return $masalah;
    }

    public function masalahIdentitas(BuktiPotong $bukti): array
    {
        $masalah = [];

        if (blank($bukti->penerima_nama) || $bukti->penerima_nama === '-') {
            $masalah[] = 'Nama penerima penghasilan kosong.';
        }

        $punyaNpwp = filled($bukti->penerima_npwp);
        $punyaNik = filled($bukti->penerima_nik);

        if (!$punyaNpwp && !$punyaNik) {
            $masalah[] = 'Penerima belum memiliki NPWP maupun NIK.';
        }

        if ($punyaNpwp && !IdentitasPajak::npwpValid($bukti->penerima_npwp)) {
            $masalah[] = 'NPWP penerima harus 16 digit angka.';
        }

        if (!$punyaNpwp && $punyaNik && !IdentitasPajak::nikValid($bukti->penerima_nik)) {
            $masalah[] = 'NIK penerima harus 16 digit angka.';
        }

        if ($punyaNpwp && blank($bukti->penerima_nitku)) {
            $masalah[] = 'NITKU penerima belum diisi.';
        }

        if (filled($bukti->penerima_nitku) && !IdentitasPajak::nitkuValid($bukti->penerima_nitku)) {
            $masalah[] = 'NITKU penerima harus 22 digit angka.';
        }

        return $masalah;
    }

    public function npwpPenerimaValid(BuktiPotong $bukti): bool
    {
        return IdentitasPajak::npwpValid($bukti->penerima_npwp);
    }

    public function terbitkan(BuktiPotong $bukti, string $nomorBuktiDjp, ?string $tanggalDiterbitkan, User $oleh): BuktiPotong
    {
        return DB::transaction(function () use ($bukti, $nomorBuktiDjp, $tanggalDiterbitkan, $oleh) {
            $bukti = BuktiPotong::with('pembayaran')->lockForUpdate()->findOrFail($bukti->id);

            if (!$bukti->isDraft()) {
                throw new RuntimeException('Hanya bukti potong berstatus DRAFT yang dapat diterbitkan.');
            }

            if ($bukti->pembayaran?->status !== PembayaranFaktur::POSTED) {
                throw new RuntimeException('Pembayaran sumber sudah dibatalkan; bukti potong tidak dapat diterbitkan.');
            }

            $masalah = $this->masalahPenerbitan($bukti);

            if ($masalah !== []) {
                throw new RuntimeException('Bukti potong belum dapat diterbitkan: ' . implode(' ', $masalah));
            }

            $nomor = trim($nomorBuktiDjp);

            if ($nomor === '') {
                throw new RuntimeException('Nomor bukti potong dari Coretax wajib diisi.');
            }

            if (BuktiPotong::where('nomor_bukti_djp', $nomor)->where('id', '!=', $bukti->id)->exists()) {
                throw new RuntimeException('Nomor bukti potong ' . $nomor . ' sudah dipakai bukti potong lain.');
            }

            $bukti->update([
                'status' => BuktiPotong::DITERBITKAN,
                'nomor_bukti_djp' => $nomor,
                'tanggal_diterbitkan' => $tanggalDiterbitkan ?: today()->toDateString(),
                'diterbitkan_oleh' => $oleh->id,
                'diterbitkan_pada' => now(),
            ]);

            return $bukti;
        });
    }

    public function batalkan(BuktiPotong $bukti, string $alasan, User $oleh): BuktiPotong
    {
        return DB::transaction(function () use ($bukti, $alasan, $oleh) {
            $bukti = BuktiPotong::lockForUpdate()->findOrFail($bukti->id);

            if (!in_array($bukti->status, [BuktiPotong::DRAFT, BuktiPotong::DITERBITKAN], true)) {
                throw new RuntimeException('Bukti potong ini sudah ' . $bukti->status . ' dan tidak dapat dibatalkan lagi.');
            }

            $bukti->update([
                'status' => BuktiPotong::DIBATALKAN,
                'perlu_pembatalan' => false,
                'catatan' => mb_substr($alasan, 0, 300),
                'dibatalkan_oleh' => $oleh->id,
                'dibatalkan_pada' => now(),
            ]);

            return $bukti;
        });
    }

    public function buatPembetulan(BuktiPotong $bukti, User $oleh): BuktiPotong
    {
        return DB::transaction(function () use ($bukti, $oleh) {
            $lama = BuktiPotong::with(['pembayaran.invoice.supplier' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->findOrFail($bukti->id);

            if (!$lama->isDiterbitkan()) {
                throw new RuntimeException('Pembetulan hanya dapat dibuat dari bukti potong yang sudah DITERBITKAN.');
            }

            if ($lama->pembayaran?->status !== PembayaranFaktur::POSTED) {
                throw new RuntimeException('Pembayaran sumber sudah dibatalkan; batalkan bukti potong ini, bukan membuat pembetulan.');
            }

            $baru = BuktiPotong::create($this->dataDariPembayaran($lama->pembayaran) + [
                'pembayaran_id' => $lama->pembayaran_id,
                'urutan' => $lama->urutan,
                'versi' => $lama->versi + 1,
                'status' => BuktiPotong::DRAFT,
                'menggantikan_id' => $lama->id,
                'dibuat_oleh' => $oleh->id,
            ]);

            $lama->update([
                'status' => BuktiPotong::DIGANTI,
                'perlu_pembatalan' => false,
                'catatan' => 'Digantikan oleh pembetulan versi ' . $baru->versi . '.',
            ]);

            return $baru;
        });
    }

    public function daftar(string $masa, ?string $status = null): Collection
    {
        [$awal, $akhir] = $this->rentang($masa);

        return BuktiPotong::with(['supplier', 'pembayaran.invoice'])
            ->whereBetween('tanggal_pemotongan', [$awal, $akhir])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('tanggal_pemotongan')
            ->orderBy('id')
            ->get();
    }

    public function tieOut(string $masa): array
    {
        [$awal, $akhir] = $this->rentang($masa);
        $hasil = [];

        foreach (BuktiPotong::JENIS_DIDUKUNG as $jenis) {
            $bukti = round((float) BuktiPotong::aktif()
                ->where('jenis_pph', $jenis)
                ->whereBetween('tanggal_pemotongan', [$awal, $akhir])
                ->sum('jumlah_pph'), 2);

            try {
                $akunId = AccountingSetting::accountId(AccountingSetting::PPH_LIABILITY_KEY[$jenis]);
            } catch (RuntimeException) {
                $hasil[$jenis] = ['jenis' => $jenis, 'bukti' => $bukti, 'buku_besar' => null, 'selisih' => null, 'bisa_dihitung' => false];
                continue;
            }

            $buku = round($this->kreditBersihPelunasan($akunId, $awal, $akhir), 2);

            $hasil[$jenis] = [
                'jenis' => $jenis,
                'bukti' => $bukti,
                'buku_besar' => $buku,
                'selisih' => round($bukti - $buku, 2),
                'bisa_dihitung' => true,
            ];
        }

        return $hasil;
    }

    private function kreditBersihPelunasan(int $akunId, string $awal, string $akhir): float
    {
        $langsung = DB::table('wms_jurnal_detail as d')
            ->join('wms_jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->whereIn('j.status', Jurnal::STATUS_BERLAKU)
            ->where('d.coa_id', $akunId)
            ->where('j.sumber_transaksi', 'PELUNASAN_HUTANG')
            ->whereBetween('j.tanggal', [$awal, $akhir])
            ->selectRaw('COALESCE(SUM(d.kredit - d.debit), 0) as bersih')
            ->value('bersih');

        $pembalik = DB::table('wms_jurnal_detail as d')
            ->join('wms_jurnal as r', 'r.id', '=', 'd.jurnal_id')
            ->join('wms_jurnal as o', 'o.id', '=', 'r.reversal_of_id')
            ->whereIn('r.status', Jurnal::STATUS_BERLAKU)
            ->where('d.coa_id', $akunId)
            ->where('o.sumber_transaksi', 'PELUNASAN_HUTANG')
            ->whereBetween('o.tanggal', [$awal, $akhir])
            ->selectRaw('COALESCE(SUM(d.kredit - d.debit), 0) as bersih')
            ->value('bersih');

        return (float) $langsung + (float) $pembalik;
    }
}
