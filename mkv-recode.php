#!/usr/bin/php
<?php

// This script is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This script is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this script.  If not, see <http://www.gnu.org/licenses/>.

/**
 * mkv-to-m2ts.php
 *
 * This is a script that can be used to convert an MKV video file containing an h.264 video stream and
 * a DTS or AC3 audio stream into an M2TS format. Specifically, this script takes MKVs and produces
 * video files which can be played on a Sony Playstation 3 with full multichannel audio support.
 *
 * Required command-line software packages / tools:
 * - ffmpeg     -- htttp://ffmpeg.org/
 * - mediainfo  -- http://mediainfo.sourceforge.net/en/
 * - mkvtoolnix -- http://www.bunkus.org/videotools/mkvtoolnix/
 * 
 * @author    Justin Filip <jfilip@gmail.com>
 * @copyright 2012 -- Justin Filip
 * @link      https://github.com/jfilip/mkv-to-m2ts
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Display the arguments available for this program that are required and optinal for it's use
 */
function print_usage($argv) {
	echo $argv[0].' --in=input_filename [--out=output_filename] [--tmp=temp_dir]'."\n\n";
	exit;
}

/**
 * Validate the input parameters and get our setup object started
 */
function validate_parameters(&$setup, $options) {
	if (!file_exists($options['in'])) {
		die('ERROR: invalid input file "'.$options['in'].'"'."\n");
	}

	$setup['file_in'] = $options['in'];

	// If an output dir/file was specified, validate that and setup the appropriate output file path and name
	if (isset($options['out'])) {
		// If a directory was specified, store the converted file there using the same filename with the .m2ts extension
		if (is_dir($options['out'])) {
			if ($options['out'][strlen($options['out']) - 1] != '/') {
				$options['out'] .= '/';
			}
			$setup['file_out'] = $options['out'].basename($setup['file_in'], '.mkv').'.m2ts';

		// If a full file path was specified, verify that the directory exists and the given file name does not
		} else {
			$pathinfo = pathinfo($options['out']);

			// Check if a malformed directory name was specified as input (output file must contain .m2ts extension)
			if (($pathinfo['basename'] == $pathinfo['filename']) && !is_dir($options['out'])) {
				die('ERROR: "'.$pathinfo['dirname'].'" is not a valid directory'."\n");
			}
			if (file_exists($options['out'])) {
				die('ERROR: cannot write output to "'.$options['out'].'"" as that file already exists'."\n");
			}
			if (substr($options['out'], -4) !== '.m2ts') {
				die('ERROR: output filename must use the .m2ts extension'."\n");
			}

			$setup['file_out'] = $options['out'];
		}
	} else {
		// Just use the input filename with the .m2ts and write the new file in the same directory as the input file
		$pathinfo = pathinfo($setup['file_in']);

		$setup['file_out'] = $pathinfo['dirname'].'/'.basename($setup['file_in'], '.mkv').'.m2ts';
	}

	// If a temp directory was specified, validate that it is a directory
	if (isset($options['tmp'])) {
		if (!is_dir($options['tmp'])) {
			die('ERROR: temp directory "'.$options['tmp'].'" is invalid');
		} else {
			$setup['temp_dir'] = $options['tmp'];
		}
	} else {
		// Use the current directory as the temporary storage
		$setup['temp_dir'] = getcwd();
	}

	if ($setup['temp_dir'][strlen($setup['temp_dir']) - 1] != '/') {
		$setup['temp_dir'] .= '/';
	}
}

/**
 * Check for required programs
 */
function check_requirements(&$setup) {
	$setup['programs'] = array(
		'mediainfo'  => '',
		'ffmpeg'	 => '',
		'mkvextract' => '',
		'dcadec'     => '',
		'aften'		 => '',
		'tsMuxeR'	 => ''
	);
	
	foreach ($setup['programs'] as $prog => $path) {
		$output = array();
		exec('which '.$prog, $output, $return);
		if ($return != 0) {
			die('ERROR: Could not find path for executable "'.$prog.'"'."\n");
		}
		$setup['programs'][$prog] = $output[0];
	}
}

/**
 * Check for a valid input file and setup some variables for the transcoding process
 */
function validate_input(&$setup, $options) {
	// Get an XML document describing the input file's container and various A/V streams within
	exec($setup['programs']['mediainfo'].' --Output=XML "'.$setup['file_in'].'"', $output, $return);

	if ($return != 0) {
		die('ERROR: executing command: "'.$setup['programs']['mediainfo'].' --Output=XML '.$setup['file_in'].'"'."\n");
	}

	$mediainfoxml = implode("\n", $output);

	$mediainfo = new SimpleXMLElement($mediainfoxml);

	// Check the container format of the input file
	$container_format = $mediainfo->xpath('File/track[@type="General"]/Format');
	if (!is_array($container_format)) {
		die('ERROR: No input container format specified')."\n";
	}
	if (strtoupper(current($container_format)) != 'MATROSKA') {
		die('ERROR: Invalid input container format: '.(string)current($container_format)."\n");
	}

	// Check for a video stream
	$video_stream = $mediainfo->xpath('File/track[@type="Video"]');

	if (!is_array($video_stream)) {
		die('ERROR: No input video stream found'."\n");
	}

	$video_stream = current($video_stream);

	$video_id = $video_stream->xpath('./ID');
	if (empty($video_id)) {
		die('ERROR: no video stream ID found'."\n");
	}

	$setup['video_stream'] = (int)current($video_id) - 1;

	// Check for a valid video stream in the input file
	$video_format = $video_stream->xpath('./Codec_ID');
	if (!is_array($video_format)) {
		die('ERROR: No input video codec specified'."\n");
	}

	$video_format = current($video_format);

	if (strtoupper($video_format) != 'V_MPEG4/ISO/AVC') {
		die('ERROR: Invalid input video codec: '.(string)$video_format."\n");
	}

	// Check for the format level of the video stream
	$format_level = $video_stream->xpath('./Format_profile');
	if (!is_array($format_level)) {
		die('ERROR: No video format level found'."\n'");
	}

	$format_level = (string)current($format_level);
	preg_match('/@L([1-9]\.[0-9])/', $format_level, $matches);

	if (!isset($matches[1])) {
		die('ERROR: could not detect valid video format level'."\n");
	}
	$setup['video_format_level'] = (float)$matches[1];

	// Check for the FPS of the video stream
	$frame_rate = $video_stream->xpath('./Frame_rate');
	if (!is_array($frame_rate)) {
		die('ERROR: No video format frame rate found'."\n'");
	}

	$frame_rate = (string)current($frame_rate);
	preg_match('/([1-9]+\.[0-9]+) fps/', $frame_rate, $matches);
	if (!isset($matches[1])) {
		die('ERROR: could not detect valid video frame rate level'."\n");
	}
	$setup['video_fps'] = $matches[1];

	// Check for a valid audio stream in the input file
	$audio_streams = $mediainfo->xpath('File/track[@type="Audio"]');
	if (!is_array($audio_streams)) {
		die('ERROR: No input audio streams specified'."\n");
	}

	foreach ($audio_streams as $audio_stream) {
		if (isset($setup['audio_stream'])) {
			continue;
		}
		// var_dump($audio_stream);
		$audio_format = $audio_stream->xpath('./Codec_ID');
		if (!is_array($audio_format)) {
			die('ERROR: No input audio codec specified'."\n");
		}

		$audio_format = current($audio_format);

		// Prefer a DTS stream over an AC3 stream in the case where both are present
		if ((string)$audio_format == 'A_DTS' || (string)$audio_format == 'A_AC3') {
			$audio_id = $audio_stream->xpath('./ID');
			if (empty($audio_id)) {
				die('ERROR: no audio stream ID found'."\n");
			}

			$setup['audio_stream'] = (int)current($audio_id) - 1;
			$setup['audio_codec']  = (string)$audio_format;

			// For DTS we need to gather more information for converting the audio into AC3 format
			if ($setup['audio_codec'] == 'A_DTS') {
				// Get the audio stream bitrate
				$audio_bitrate = $audio_stream->xpath('./Bit_rate');
				if (!is_array($audio_bitrate)) {
					die('ERROR: no audio bitrate found'."\n");
				}

				$audio_bitrate = (string)current($audio_bitrate);

				preg_match('/([0-9]+) KBPS/', strtoupper($audio_bitrate), $matches);
				$setup['audio_bitrate'] = $matches[1];

				// Get the audio stream channel count
				$audio_channels = $audio_stream->xpath('./Channel_s_');
				if (!is_array($audio_channels)) {
					die('ERROR: no audio channel information found'."\n");
				}

				$audio_channels = (string)current($audio_channels);

				preg_match('/([0-9]) CHANNELS/', strtoupper($audio_channels), $matches);
				$setup['audio_channels'] = $matches[1];
			}
		}
	}

	if (!isset($setup['audio_codec'])) {
		die('ERROR: No valid audio video codecs found'."\n");
	}
}

/**
 * Perform the actual transcode process based on the variables setup from the input validation
 */
function perform_transcode($setup) {
	// Extract the video stream into a file
	echo 'Extracting video stream ... ';
	exec($setup['programs']['mkvextract'].' tracks "'.$setup['file_in'].'" '.$setup['video_stream'].':'.$setup['temp_dir'].'video.h264', $output, $return);
	if ($return != 0) {
		die('ERROR: Failure while executing mkvextract'."\n\n".implode("\n", $output));
	}
	echo 'done!'."\n";

	

	// DTS audio must be converted to AC3 format
	if ($setup['audio_codec'] == 'A_DTS') {
		echo 'Extracting audio stream ... ';
		exec($setup['programs']['mkvextract'].' tracks "'.$setup['file_in'].'" '.$setup['audio_stream'].':'.$setup['temp_dir'].'audio.dts', $output, $return);
		if ($return != 0) {
			die('ERROR: Failure while executing mkvextract'."\n\n".implode("\n", $output));
		}
		echo 'done!'."\n";

		echo 'Converting DTS audio stream to AC3 ... ';
		exec($setup['programs']['dcadec'].' -o wavall "'.$setup['temp_dir'].'audio.dts" | aften -b 640 -v 0 - "'.$setup['temp_dir'].'audio.ac3"', $output, $return);
		if ($return != 0) {
			die('ERROR: Failure while executing dcadec and aften'."\n\n".implode("\n", $output));
		}
		echo 'done!'."\n";
	} else {
		echo 'Extracting audio stream ... ';
		exec($setup['programs']['mkvextract'].' tracks "'.$setup['file_in'].'" '.$setup['audio_stream'].':'.$setup['temp_dir'].'audio.ac3', $output, $return);
		if ($return != 0) {
			die('ERROR: Failure while executing mkvextract'."\n\n".implode("\n", $output));
		}
		echo 'done!'."\n";
	}

	// Generate the meta information file for tsMuxeR
	if (!$fh = fopen($setup['temp_dir'].'tsmuxer.meta', 'w+')) {
		die('ERRROR: Could not open meta file for writing'."\n");
	}

	fwrite($fh, 'MUXOPT --no-pcr-on-video-pid --new-audio-pes --vbr  --vbv-len=500'."\n");

	if ($setup['video_format_level'] == 4.1 || $setup['video_format_level'] > 5) {
		fwrite($fh, 'V_MPEG4/ISO/AVC, "'.$setup['temp_dir'].'video.h264", level=4.1, insertSEI, contSPS, lang=eng, fps='.$setup['video_fps']."\n");
	} else {
		fwrite($fh, 'V_MPEG4/ISO/AVC, "'.$setup['temp_dir'].'video.h264", insertSEI, contSPS, lang=eng, fps='.$setup['video_fps']."\n");
	}

	fwrite($fh, 'A_AC3, "'.$setup['temp_dir'].'audio.ac3"'."\n");
	fclose($fh);

	echo 'Packaging M2TS file ...';
	exec($setup['programs']['tsMuxeR'].' '.$setup['temp_dir'].'tsmuxer.meta "'.$setup['file_out'].'"', $output, $return);
	if ($return != 0) {
		die('ERROR: Failure while executing tsMuxeR'."\n\n".implode("\n", $output));
	}
	echo 'done!'."\n";

	echo 'Cleaning up temporary files ... ';
	unlink($setup['temp_dir'].'video.h264');
	if ($setup['audio_codec'] == 'A_DTS') {
		unlink($setup['temp_dir'].'audio.dts');
	}
	unlink($setup['temp_dir'].'audio.ac3');
	unlink($setup['temp_dir'].'tsmuxer.meta');
	echo 'done!'."\n";
}


/**
 * Main program exeuction below
 */


// Get CLI script arguments
if (($options = getopt('', array('in:', 'out::', 'tmp::'))) == false) {
	print_usage($argv);
}

$setup = array();

validate_parameters($setup, $options);
check_requirements($setup);
validate_input($setup, $options);
perform_transcode($setup);
