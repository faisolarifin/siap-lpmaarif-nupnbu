<?php

namespace App\Http\Controllers;

use App\Export\ExportDocument;
use App\Helpers\GenerateQr;
use App\Mail\StatusMail;
use App\Models\FileUpload;
use App\Models\Kabupaten;
use App\Models\Kategori;
use App\Models\Provinsi;
use App\Models\Satpen;
use App\Models\Jenjang;
use App\Models\Timeline;
use Illuminate\Support\Facades\Date;
use App\Http\Requests\StatusSatpenRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminController extends Controller
{
    public function dashboardPage() {
        return view('admin.home.dashboard');
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|\Illuminate\Http\RedirectResponse|void
     */

    public function getAllSatpenOrFilter(Request $request)
    {
        $paginatePerPage = 10;
        $selectedColumns = ['id_satpen', 'id_kategori', 'id_kab', 'id_prov', 'id_jenjang', 'no_registrasi', 'nm_satpen', 'yayasan', 'thn_berdiri', 'status', 'tgl_registrasi'];
        try {
            /**
             * If request without satpenid show all satpen where status 'setujui'
             */
            if ($request->jenjang
                    || $request->kabupaten
                    || $request->provinsi
                    || $request->kategori) {

                $filter = [];
                if ($request->jenjang) $filter["id_jenjang"] = $request->jenjang;
                if ($request->kabupaten) $filter["id_kab"] = $request->kabupaten;
                if ($request->provinsi) $filter["id_prov"] = $request->provinsi;
                if ($request->kategori) $filter["id_kategori"] = $request->kategori;
//                if ($request->yayasan) $filter["yayasan"] = $request->yayasan;
//                if ($request->satpen) array_push($filter, ["nm_satpen", "like", "%". $request->satpen ."%"]);

                if ($filter) {
                    $satpenProfile = Satpen::with([
                        'kategori:id_kategori,nm_kategori',
                        'provinsi:id_prov,nm_prov',
                        'kabupaten:id_kab,nama_kab',
                        'jenjang:id_jenjang,nm_jenjang',
                    ])
                        ->select($selectedColumns)
                        ->whereIn('status', ['setujui', 'expired'])
                        ->where($filter)
                        ->paginate($paginatePerPage);
                }
            }
            else {
                $satpenProfile = Satpen::with([
                    'kategori:id_kategori,nm_kategori',
                    'provinsi:id_prov,nm_prov',
                    'kabupaten:id_kab,nama_kab',
                    'jenjang:id_jenjang,nm_jenjang',
                ])
                    ->select($selectedColumns)
                    ->whereIn('status', ['setujui', 'expired'])
                    ->paginate($paginatePerPage);
            }

            /**
             * If satpen profile null is user access satpen id not releate with user id
             */
            if (!$satpenProfile) return redirect()->back()->with('error', 'Forbidden to access satpen profile');

            $propinsi = Provinsi::all();
            $jenjang = Jenjang::all();
            $kategori = Kategori::all();

            return view('admin.satpen.rekapsatpen', compact('satpenProfile',
                'propinsi', 'jenjang', 'kategori'));

        } catch (\Exception $e) {
            dd($e);
        }
    }

    public function getSatpenById(string $satpenId=null) {
        try {
            if ($satpenId) {
                $satpenProfile = Satpen::with(['kategori', 'provinsi', 'kabupaten', 'jenjang', 'timeline', 'filereg'])
                    ->where('id_satpen', '=', $satpenId)
                    ->first();
                if (!$satpenProfile) return redirect()->back()->with('error', 'Forbidden to access satpen profile');

                return view('admin.satpen.detailSatpen', compact('satpenProfile'));

            }
        } catch (\Exception $e) {
            dd($e);
        }
    }

    public function permohonanRegisterSatpen() {
        try {
            $selectedColumns = ['id_satpen', 'id_kategori', 'id_kab', 'id_prov', 'id_jenjang', 'npsn', 'no_registrasi', 'nm_satpen', 'yayasan', 'status'];
            $relationTable = [
                'kategori:id_kategori,nm_kategori',
                'provinsi:id_prov,nm_prov',
                'kabupaten:id_kab,nama_kab',
                'jenjang:id_jenjang,nm_jenjang',
            ];

            $permohonanSatpens = Satpen::with($relationTable)->where('status', '=', 'permohonan')->get($selectedColumns);
            $revisiSatpens = Satpen::with($relationTable)->where('status', '=', 'revisi')->get(array_merge($selectedColumns, ['kecamatan']));
            $prosesDocuments = Satpen::with($relationTable)->where('status', '=', 'proses dokumen')->get(array_merge($selectedColumns, ['kecamatan']));
            $perpanjanganDocuments = Satpen::with($relationTable)->where('status', '=', 'perpanjangan')->get(array_merge($selectedColumns, ['kecamatan']));

            return view('admin.satpen.registersatpen', compact('permohonanSatpens', 'revisiSatpens', 'prosesDocuments', 'perpanjanganDocuments'));

        } catch (\Exception $e) {
            dd($e);
        }
    }

    public function updateSatpenStatus(StatusSatpenRequest $request, Satpen $satpen)
    {
        try {
            if ($satpen->status == $request->status_verifikasi) return redirect()->back()->with('error', 'status satpen sudah sudah sesuai');

            $satpen->update([
                'status' => $request->status_verifikasi
            ]);

            Timeline::create([
                'id_satpen' => $satpen->id_satpen,
                'status_verifikasi' => $request->status_verifikasi,
                'tgl_status' => Date::now(),
                'keterangan' => $request->keterangan,
            ]);

            Mail::to($satpen->email)->send(new StatusMail($request->status_verifikasi));

            return redirect()->back()->with('success', 'Status satpen telah diupdate menjadi '. $request->status_verifikasi);

        } catch (\Exception $e) {
            dd($e);
        }
    }

    public function pdfUploadViewer(string $fileName) {
        if ($fileName) {
            $filepath = storage_path("app/uploads/".$fileName);

            if (!file_exists($filepath)) return response("File not found!");
            return response()->file($filepath);
        }
        return response("Invalid Document!");
    }

    public function pdfGeneratedViewer(string $type=null, string $fileName=null) {
        if ($fileName && $type) {
            $filepath = storage_path("app/generated/".$type."/".$fileName);

            if (!file_exists($filepath)) return response("File not found!");
            return response()->file($filepath);
        }
        return response("Invalid Document!");
    }

    public function generatePiagamAndSK(Request $request) {
        try {
            $piagamFilename = "Piagam Nomor Registrasi Ma'arif - ";
            $skFilename = "SK Satuan Pendidikan BHPNU - ";
            /**
             * generate ordered nomor surat
             */
            $autoNomorSurat = 1;
            $maxNomorSurat = FileUpload::where('typefile', '=', 'sk')->max('no_file');
            if (!$maxNomorSurat) {
                $autoNomorSurat = (int) ++$maxNomorSurat;
            }
            /**
             * get selected satpen
             */
            $satpen = Satpen::find($request->satpenid);

            if ($satpen) {
                /**
                 * create file data in db.file_upload
                 */
                $piagamFilename .= $satpen->nm_satpen.".pdf";
                $skFilename .= $satpen->nm_satpen.".pdf";
                //create piagam
                FileUpload::create([
                    'id_satpen' => $satpen->id_satpen,
                    'typefile' => "piagam",
                    'qrcode' => GenerateQr::encodeQr($satpen->id_satpen, "sk"),
                    'nm_file' => $piagamFilename,
                    'tgl_file' => $request->tgl_doc,
                ]);
                FileUpload::create([
                    'id_satpen' => $satpen->id_satpen,
                    'typefile' => "sk",
                    'no_file' => $autoNomorSurat,
                    'qrcode' => GenerateQr::encodeQr($satpen->id_satpen, "piagam"),
                    'nm_file' => $skFilename,
                    'tgl_file' => $request->tgl_doc,
                ]);

                /**
                 * get relation data
                 */
                $satpenProfile = Satpen::with(['kategori', 'provinsi', 'kabupaten', 'jenjang', 'filereg', 'file'])
                    ->where('id_satpen', '=', $satpen->id_satpen)
                    ->first();

                /**
                 * Create pdf document and save in server
                 */
                ExportDocument::makePiagamDokumen($satpenProfile);
                ExportDocument::makeSKDokumen($satpenProfile);

                /**
                 * update status satpen menjadi disetujui
                 */
                $satpen->update([
                    'tgl_registrasi' => Date::now(),
                ]);

                $this->updateSatpenStatus((new StatusSatpenRequest())
                    ->merge(["status_verifikasi" => "setujui"]),
                    $satpen);

                return redirect()->back()->with('success', 'Dokumen Surat Keputusan dan Piagam telah generate');
            }

            return redirect()->back()->with('error', 'satpen tidak ditemukan!');

        } catch (\Exception $e) {
            dd($e);
        }
    }

    public function reGeneratePiagamAndSK(Request $request) {
        try {
            $piagamFilename = "Piagam Nomor Registrasi Ma'arif - ";
            $skFilename = "SK Satuan Pendidikan BHPNU - ";
            /**
             * generate ordered nomor surat
             */
            $autoNomorSurat = 1;
            $maxNomorSurat = FileUpload::where('typefile', '=', 'sk')->max('no_file');
            if (!$maxNomorSurat) {
                $autoNomorSurat = (int) ++$maxNomorSurat;
            }
            /**
             * get selected satpen
             */
            $satpen = Satpen::find($request->satpenid);

            if ($satpen) {
                /**
                 * create file data in db.file_upload
                 */
                $piagamFilename .= $satpen->nm_satpen.".pdf";
                $skFilename .= $satpen->nm_satpen.".pdf";
                //create piagam
                FileUpload::where([
                    'id_satpen' => $satpen->id_satpen,
                    'typefile' => "piagam",
                ])->update([
                    'qrcode' => GenerateQr::encodeQr($satpen->id_satpen, "sk"),
                    'nm_file' => $piagamFilename,
                    'tgl_file' => $request->tgl_doc,
                ]);
                FileUpload::where([
                    'id_satpen' => $satpen->id_satpen,
                    'typefile' => "sk",
                ])->update([
                    'no_file' => $autoNomorSurat,
                    'qrcode' => GenerateQr::encodeQr($satpen->id_satpen, "piagam"),
                    'nm_file' => $skFilename,
                    'tgl_file' => $request->tgl_doc,
                ]);

                /**
                 * get relation data
                 */
                $satpenProfile = Satpen::with(['kategori', 'provinsi', 'kabupaten', 'jenjang', 'filereg', 'file'])
                    ->where('id_satpen', '=', $satpen->id_satpen)
                    ->first();

                /**
                 * Create pdf document and save in server
                 */
                ExportDocument::makePiagamDokumen($satpenProfile);
                ExportDocument::makeSKDokumen($satpenProfile);

                /**
                 * update status satpen menjadi disetujui
                 */
                $satpen->update([
                    'tgl_registrasi' => Date::now(),
                ]);

                $this->updateSatpenStatus((new StatusSatpenRequest())
                    ->merge(["status_verifikasi" => "setujui"]),
                    $satpen);

                return redirect()->back()->with('success', 'Dokumen Surat Keputusan dan Piagam telah regenerate');
            }

            return redirect()->back()->with('error', 'satpen tidak ditemukan!');

        } catch (\Exception $e) {
            dd($e);
        }
    }


    public function underConstruction() {
        return view('template.constructionad');
    }


}
