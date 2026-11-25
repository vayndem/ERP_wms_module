BLUEPRINT.scene('setup', {
  title: 'Persiapan: akun, gudang, master',
  fig: 1,
  sheet: 'LEMBAR 1 DARI 4',
  size: [800, 400],
  parts: [
    { id: 'sa', kind: 'screen', at: [100, 200], size: [100, 74], label: 'SUPER ADMIN', n: 1, lead: 'nw', name: 'Super Admin', note: 'satu-satunya yang boleh membuat, mengubah, dan menonaktifkan akun' },
    { id: 'akun', kind: 'box', at: [260, 200], size: [96, 56], label: 'AKUN\n7 ROLE', n: 2, lead: 'n', name: 'Akun pengguna', note: 'tiap akun punya satu role, dan role menentukan menu serta tombol' },
    { id: 'j', kind: 'dot', at: [340, 200] },
    { id: 'gud', kind: 'box', at: [440, 100], size: [112, 56], label: 'PEMBAGIAN\nGUDANG', n: 3, lead: 'n', name: 'Pembagian gudang (Warehouse)', note: 'operator Warehouse dan Produksi hanya melihat gudang yang ditugaskan' },
    { id: 'mst', kind: 'box', at: [440, 200], size: [112, 56], label: 'SUPPLIER\nBAHAN', n: 4, lead: 'n', name: 'Master (Purchasing)', note: 'supplier dibuat Purchasing; bahan boleh oleh Purchasing, Warehouse, Accounting' },
    { id: 'coa', kind: 'box', at: [440, 300], size: [112, 56], label: 'COA\nMAPPING', n: 5, lead: 'n', name: 'Bagan akun (Accounting)', note: 'akun dan mapping yang dipakai setiap jurnal otomatis' },
    { id: 'k', kind: 'dot', at: [560, 200] },
    { id: 'siap', kind: 'lamp', at: [680, 200], label: 'SIAP', n: 6, lead: 'ne', name: 'Siap transaksi', note: 'dokumen pertama baru bisa dibuat' }
  ],
  lines: [
    { id: 'l1', from: 'sa.e', to: 'akun.w', arrow: true },
    { id: 'l2', from: 'akun.e', to: 'j' },
    { id: 'l3', from: 'j', to: 'gud.w', bend: 'vh', arrow: true },
    { id: 'l4', from: 'j', to: 'mst.w', arrow: true },
    { id: 'l5', from: 'j', to: 'coa.w', bend: 'vh', arrow: true },
    { id: 'l6', from: 'gud.e', to: 'k', bend: 'hv' },
    { id: 'l7', from: 'mst.e', to: 'k' },
    { id: 'l8', from: 'coa.e', to: 'k', bend: 'hv' },
    { id: 'l9', from: 'k', to: 'siap.w', arrow: true }
  ],
  steps: [
    { say: 'Semuanya dimulai dari Super Admin (1): hanya dia yang boleh membuat akun.', draw: ['sa'] },
    { say: 'Setiap akun diberi satu dari tujuh role (2), dan role itu menentukan apa yang terlihat.', draw: ['l1', 'akun'], run: [{ on: 'l1', item: 'chip', text: 'ROLE 0-6' }] },
    { say: 'Tapi operator baru belum melihat gudang apa pun. Jadi Warehouse (3) membagi gudangnya.', draw: ['l2', 'j', 'l3', 'gud'], run: [{ on: 'l3', item: 'ball' }], note: { at: 'gud.e', text: 'BARU = 0 GUDANG', dx: 10, anchor: 'start' } },
    { say: 'Belum ada barang dan pemasok. Jadi Purchasing mengisi master (4).', draw: ['l4', 'mst'], run: [{ on: 'l4', item: 'ball' }] },
    { say: 'Jurnal otomatis butuh akun. Jadi Accounting menyiapkan bagan akun dan mapping (5).', draw: ['l5', 'coa'], run: [{ on: 'l5', item: 'ball' }] },
    { say: 'Setelah ketiganya siap (6), dokumen pertama baru bisa dibuat.', draw: ['l6', 'l7', 'l8', 'k', 'l9', 'siap'], run: [{ on: 'l6', item: 'ball' }, { on: 'l7', item: 'ball', with: true }, { on: 'l8', item: 'ball', with: true }, { on: 'l9', item: 'ball' }], light: ['siap'], ok: ['siap'], note: { at: 'siap.n', text: 'MULAI TRANSAKSI', dx: -20, dy: -16 } }
  ]
});

BLUEPRINT.scene('beli', {
  title: 'Alur beli: dari request sampai bayar',
  fig: 2,
  sheet: 'LEMBAR 2 DARI 4',
  size: [800, 400],
  parts: [
    { id: 'req', kind: 'box', at: [100, 120], size: [104, 56], label: 'REQUEST\nNON-PURCHASING', n: 1, lead: 'n', name: 'Request barang', note: 'dibuat semua role kecuali Purchasing; status awal PENDING' },
    { id: 'apv', kind: 'box', at: [260, 120], size: [104, 56], label: 'APPROVE\nPURCH · ACCT', n: 2, lead: 'n', name: 'Persetujuan request', note: 'Purchasing atau Accounting; bahan baru otomatis masuk master' },
    { id: 'po', kind: 'box', at: [420, 120], size: [104, 56], label: 'PO\nPURCH · ACCT', n: 3, lead: 'n', name: 'Purchase order', note: 'dibuat Purchasing atau Accounting' },
    { id: 'lpb', kind: 'box', at: [580, 120], size: [104, 56], label: 'LPB\nWH · PURCH', n: 4, lead: 'n', name: 'Penerimaan barang (LPB)', note: 'dibuat Warehouse atau Purchasing; langsung POSTED' },
    { id: 'stok', kind: 'cabinet', at: [720, 120], size: [64, 80], label: 'STOK FIFO', n: 5, lead: 'ne', name: 'Stok dan lapisan FIFO', note: 'terbentuk saat LPB; dipakai NPK dan surat jalan' },
    { id: 'fak', kind: 'box', at: [580, 290], size: [104, 56], label: 'FAKTUR\nPURCH · ACCT', n: 6, lead: 's', name: 'Faktur pembelian', note: 'digabung dari LPB; status PENDING_APPROVAL' },
    { id: 'mgr', kind: 'box', at: [420, 290], size: [104, 56], label: 'APPROVE\nACC MANAGER', n: 7, lead: 'n', name: 'Accounting Manager', note: 'satu-satunya yang boleh menyetujui faktur supplier' },
    { id: 'byr', kind: 'box', at: [260, 290], size: [104, 56], label: 'BAYAR\nFINANCE', n: 8, lead: 's', name: 'Pembayaran', note: 'Finance membayar, memotong PPh, atau pakai uang muka' },
    { id: 'jur', kind: 'drum', at: [100, 290], size: [64, 80], label: 'JURNAL', n: 9, lead: 'nw', name: 'Jurnal akuntansi', note: 'dibentuk otomatis dari setiap langkah' }
  ],
  lines: [
    { id: 'a1', from: 'req.e', to: 'apv.w', arrow: true },
    { id: 'a2', from: 'apv.e', to: 'po.w', arrow: true },
    { id: 'a3', from: 'po.e', to: 'lpb.w', arrow: true },
    { id: 'a4', from: 'lpb.e', to: 'stok.w', arrow: true },
    { id: 'a5', from: 'lpb.s', to: 'fak.n', arrow: true },
    { id: 'a6', from: 'fak.w', to: 'mgr.e', arrow: true },
    { id: 'a7', from: 'mgr.w', to: 'byr.e', arrow: true },
    { id: 'a8', from: 'byr.w', to: 'jur.e', arrow: true }
  ],
  steps: [
    { say: 'Orang gudang atau produksi butuh bahan, lalu membuat request (1).', draw: ['req'], note: { at: 'req.s', text: 'PENDING' } },
    { say: 'Request belum berarti beli. Jadi Purchasing atau Accounting menyetujui jumlahnya (2).', draw: ['a1', 'apv'], run: [{ on: 'a1', item: 'chip', text: 'REQ-1' }], note: { at: 'apv.s', text: 'APPROVED' } },
    { say: 'Setelah disetujui, Purchasing atau Accounting memesan ke pemasok lewat PO (3).', draw: ['a2', 'po'], run: [{ on: 'a2', item: 'chip', text: 'REQ-1' }] },
    { say: 'Barang datang: Warehouse mencatat LPB (4), dan stok FIFO (5) terbentuk saat itu juga.', draw: ['a3', 'lpb', 'a4', 'stok'], run: [{ on: 'a3', item: 'chip', text: 'PO-1' }, { on: 'a4', item: 'packet', count: 3, gap: 0.2 }], note: { at: 'lpb.s', text: 'PERSEDIAAN / GRNI', dy: 14 } },
    { say: 'Pemasok menagih. Jadi Purchasing atau Accounting membuat faktur dari LPB (6).', draw: ['a5', 'fak'], run: [{ on: 'a5', item: 'chip', text: 'LPB-1' }], note: { at: 'fak.e', text: 'MENUNGGU', dx: 10, anchor: 'start' } },
    { say: 'Tapi yang menagih tidak boleh menyetujui. Jadi Accounting Manager memeriksanya (7).', draw: ['a6', 'mgr'], run: [{ on: 'a6', item: 'chip', text: 'FAK-1' }], ok: ['mgr'], note: { at: 'mgr.s', text: 'GRNI → HUTANG', dy: 14 } },
    { say: 'Baru setelah disetujui, Finance membayar (8); faktur lunas bertahap sampai PAID.', draw: ['a7', 'byr'], run: [{ on: 'a7', item: 'chip', text: 'RP' }], note: { at: 'byr.n', text: 'HUTANG / KAS', dy: -10 } },
    { say: 'Setiap langkah tadi sudah tercatat di jurnal (9), tanpa ada yang mengetik ulang.', draw: ['a8', 'jur'], run: [{ on: 'a8', item: 'packet', count: 3, gap: 0.25 }], ok: ['jur'], glow: ['jur'] }
  ]
});

BLUEPRINT.scene('jual', {
  title: 'Alur jual dan produksi',
  fig: 3,
  sheet: 'LEMBAR 3 DARI 4',
  size: [800, 400],
  parts: [
    { id: 'cus', kind: 'box', at: [100, 120], size: [104, 56], label: 'PELANGGAN\nPURCH · ACCT', n: 1, lead: 'n', name: 'Pelanggan', note: 'master dengan termin dan plafon kredit; dibuat Purchasing atau Accounting' },
    { id: 'so', kind: 'box', at: [260, 120], size: [104, 56], label: 'PESANAN\nPURCH · ACCT', n: 2, lead: 'n', name: 'Pesanan penjualan', note: 'dibuat Purchasing atau Accounting; plafon kredit dicek' },
    { id: 'sj', kind: 'box', at: [420, 120], size: [104, 56], label: 'SURAT JALAN\nWH · PROD', n: 5, lead: 'n', name: 'Surat jalan', note: 'draft oleh Warehouse atau Purchasing; posting oleh Warehouse atau Produksi sesuai gudangnya' },
    { id: 'fk', kind: 'box', at: [580, 120], size: [104, 56], label: 'FAKTUR\nACCOUNTING', n: 6, lead: 'n', name: 'Faktur penjualan', note: 'draft dari surat jalan oleh Accounting atau Finance; posting hanya Accounting' },
    { id: 'byr', kind: 'box', at: [720, 120], size: [96, 56], label: 'TERIMA BAYAR\nFINANCE', n: 7, lead: 'n', name: 'Penerimaan pembayaran', note: 'hanya Finance; kelebihan bayar jadi uang muka pelanggan' },
    { id: 'wo', kind: 'box', at: [260, 290], size: [112, 56], label: 'PERINTAH KERJA\nPRODUKSI', n: 3, lead: 's', name: 'Perintah kerja', note: 'dibuat Produksi atau Purchasing; hanya Produksi yang menyelesaikan' },
    { id: 'npk', kind: 'box', at: [420, 290], size: [104, 56], label: 'NPK → WIP\nWH · PROD', n: 4, lead: 's', name: 'NPK (pemakaian bahan)', note: 'bahan yang ditandai ke perintah kerja menumpuk di barang dalam proses' },
    { id: 'jur', kind: 'drum', at: [580, 290], size: [64, 80], label: 'JURNAL', n: 8, lead: 'se', name: 'Jurnal akuntansi', note: 'HPP, piutang, PPN keluaran, dan kas' }
  ],
  lines: [
    { id: 'b1', from: 'cus.e', to: 'so.w', arrow: true },
    { id: 'b2', from: 'so.s', to: 'wo.n', arrow: true },
    { id: 'b3', from: 'wo.e', to: 'npk.w', arrow: true },
    { id: 'b4', from: 'npk.n', to: 'sj.s', arrow: true },
    { id: 'b5', from: 'so.e', to: 'sj.w', arrow: true },
    { id: 'b6', from: 'sj.e', to: 'fk.w', arrow: true },
    { id: 'b7', from: 'fk.e', to: 'byr.w', arrow: true },
    { id: 'b8', from: 'fk.s', to: 'jur.n', arrow: true }
  ],
  steps: [
    { say: 'Pelanggan memesan. Purchasing atau Accounting mencatat pelanggan (1) dan pesanannya (2).', draw: ['cus', 'b1', 'so'], run: [{ on: 'b1', item: 'chip', text: 'PESAN' }], note: { at: 'so.s', text: 'CEK PLAFON', dy: 14 } },
    { say: 'Kalau barang harus dibuat dulu, Produksi membuka perintah kerja (3) untuk pesanan itu.', draw: ['b2', 'wo'], run: [{ on: 'b2', item: 'chip', text: 'SO-1' }] },
    { say: 'Bahan dipakai lewat NPK (4), dan nilainya menumpuk di barang dalam proses, bukan jadi beban.', draw: ['b3', 'npk'], run: [{ on: 'b3', item: 'chip', text: 'BAHAN' }], note: { at: 'npk.s', text: 'WIP', dy: 14 } },
    { say: 'Siap kirim: Warehouse membuat surat jalan (5). Saat diposting, stok FIFO berkurang.', draw: ['b4', 'b5', 'sj'], run: [{ on: 'b4', item: 'packet', count: 2, gap: 0.2 }, { on: 'b5', item: 'chip', text: 'KIRIM', with: true }], note: { at: 'sj.s', text: 'HPP · JURNAL', dx: 10, dy: 14, anchor: 'start' } },
    { say: 'Barang terkirim, tapi belum ditagih. Jadi Accounting menerbitkan faktur penjualan (6).', draw: ['b6', 'fk'], run: [{ on: 'b6', item: 'chip', text: 'SJ-1' }], note: { at: 'fk.s', text: 'PIUTANG + PPN', dy: 14 } },
    { say: 'Pelanggan membayar; Finance menerima pembayaran (7) sampai faktur lunas.', draw: ['b7', 'byr'], run: [{ on: 'b7', item: 'chip', text: 'RP' }], ok: ['byr'] },
    { say: 'Kirim, tagih, dan terima uang, semuanya bermuara ke jurnal (8).', draw: ['b8', 'jur'], run: [{ on: 'b8', item: 'packet', count: 3, gap: 0.25 }], ok: ['jur'], glow: ['jur'] }
  ]
});

BLUEPRINT.scene('kontrol', {
  title: 'Kontrol: opname, jurnal manual, pembalikan',
  fig: 4,
  sheet: 'LEMBAR 4 DARI 4',
  size: [800, 400],
  parts: [
    { id: 'op', kind: 'box', at: [100, 100], size: [104, 56], label: 'OPNAME\nWH · PROD', n: 1, lead: 'n', name: 'Stock opname', note: 'disiapkan dan diajukan Warehouse atau Produksi; DRAFT lalu SUBMITTED' },
    { id: 'apv', kind: 'box', at: [260, 100], size: [112, 56], label: 'SETUJU + POST\nACCOUNTING', n: 2, lead: 'n', name: 'Persetujuan opname', note: 'hanya Accounting yang menyetujui, menolak, dan memposting selisihnya' },
    { id: 'jm', kind: 'box', at: [100, 230], size: [104, 56], label: 'JURNAL MANUAL\nACCOUNTING', n: 3, lead: 'w', name: 'Jurnal manual', note: 'disusun Accounting; saat diposting menunggu PENDING_APPROVAL' },
    { id: 'rv', kind: 'box', at: [100, 330], size: [104, 56], label: 'PEMBALIKAN\nACCOUNTING', n: 4, lead: 'w', name: 'Permintaan pembalikan', note: 'koreksi LPB, NPK, retur, atau COA lewat permintaan, bukan edit' },
    { id: 'mgr', kind: 'box', at: [280, 280], size: [112, 56], label: 'PUTUSKAN\nACC MANAGER', n: 5, lead: 'n', name: 'Accounting Manager', note: 'memutuskan; pemohon tidak boleh menyetujui permintaannya sendiri' },
    { id: 'jur', kind: 'drum', at: [470, 190], size: [64, 80], label: 'BUKU\nBESAR', labelAt: 'in', n: 6, lead: 'ne', name: 'Jurnal dan buku besar', note: 'sumber semua laporan' },
    { id: 'gate', kind: 'gate', at: [590, 190], size: [76, 44], label: 'KUNCI\nPERIODE', n: 7, lead: 'n', open: 1, name: 'Kunci periode', note: 'periode terkunci menolak posting baru; dikelola Accounting' },
    { id: 'lap', kind: 'screen', at: [720, 190], size: [92, 70], label: 'LAPORAN', n: 8, lead: 'ne', name: 'Laporan keuangan', note: 'lima laporan PSAK 1, bisa PDF dan Excel' }
  ],
  lines: [
    { id: 'c1', from: 'op.e', to: 'apv.w', arrow: true },
    { id: 'c2', from: 'apv.e', to: 'jur.n', bend: 'hv', arrow: true },
    { id: 'c3', from: 'jm.e', to: 'mgr.w-10', bend: 'hv', arrow: true },
    { id: 'c4', from: 'rv.e', to: 'mgr.w+10', bend: 'hv', arrow: true },
    { id: 'c5', from: 'mgr.e', to: 'jur.s', bend: 'hv', arrow: true },
    { id: 'c6', from: 'jur.e', to: 'gate.w', arrow: true },
    { id: 'c7', from: 'gate.e', to: 'lap.w', arrow: true }
  ],
  steps: [
    { say: 'Stok fisik beda dari catatan? Warehouse atau Produksi menghitung dan mengajukan opname (1).', draw: ['op'], note: { at: 'op.s', text: 'SUBMITTED', dy: 14 } },
    { say: 'Selisih mengubah nilai persediaan, jadi Accounting yang menyetujui dan memostingnya (2).', draw: ['c1', 'apv', 'c2', 'jur'], run: [{ on: 'c1', item: 'chip', text: 'SELISIH' }, { on: 'c2', item: 'ball' }] },
    { say: 'Jurnal manual (3) tidak boleh langsung berlaku. Setelah diposting ia menunggu persetujuan.', draw: ['jm'], note: { at: 'jm.n', text: 'MENUNGGU', dy: -12 } },
    { say: 'Salah posting LPB atau NPK? Dokumennya tidak diedit; Accounting mengajukan pembalikan (4).', draw: ['rv'] },
    { say: 'Keduanya diputuskan Accounting Manager (5), dan pemohon tidak boleh menyetujui dirinya sendiri.', draw: ['c3', 'c4', 'mgr', 'c5'], run: [{ on: 'c3', item: 'chip', text: 'JM-1' }, { on: 'c4', item: 'chip', text: 'REV-1', with: true }, { on: 'c5', item: 'ball' }], ok: ['mgr'] },
    { say: 'Semua yang sah masuk satu buku besar (6), yang dibaca oleh seluruh laporan.', glow: ['jur'], note: { at: 'jur.w', text: 'SATU SUMBER', dx: -8, anchor: 'end' } },
    { say: 'Periode yang dikunci (7) menolak posting baru, dan laporan keuangan (8) keluar dari sana.', draw: ['c6', 'gate', 'c7', 'lap'], run: [{ on: 'c6', item: 'packet', count: 3, gap: 0.2 }, { on: 'c7', item: 'packet', count: 3, gap: 0.2 }], ok: ['lap'] }
  ]
});
