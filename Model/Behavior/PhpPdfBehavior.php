<?php

// CakePHP friendly wrapper for PHPExcel
// found at: https://github.com/PHPOffice/PHPExcel/wiki

//App::uses('CakeEmail', 'Network/Email');
//App::uses('Shell', 'Console');

// Composer is handling the autoload of the required files.
//// apparently not, if not, load them here

require_once ROOT. DS. 'Vendor'.DS.'mikehaertl'.DS.'php-tmpfile'.DS.'src'.DS.'File.php';
require_once ROOT. DS. 'Vendor'.DS.'mikehaertl'.DS.'php-shellcommand'.DS.'src'.DS.'Command.php';
require_once ROOT. DS. 'Vendor'.DS.'mikehaertl'.DS.'php-pdftk'.DS.'src'.DS.'Pdf.php';
require_once ROOT. DS. 'Vendor'.DS.'mikehaertl'.DS.'php-pdftk'.DS.'src'.DS.'FdfFile.php';
require_once ROOT. DS. 'Vendor'.DS.'mikehaertl'.DS.'php-pdftk'.DS.'src'.DS.'Command.php';

use mikehaertl\pdftk\Pdf;
use mikehaertl\pdftk\FdfFile;
use mikehaertl\pdftk\Command;

class PhpPdfBehavior extends ModelBehavior
{
	
	public $settings = array();
	
	protected $_defaults = array(
	);
	
	public $Model = false;
	public $Pdf = false;
	public $FdfFile = false;
	
	public function setup(Model $Model, $settings = array())
	{
		$this->Model = $Model;
		
		if (!isset($this->settings[$Model->alias])) 
		{
			$this->settings[$Model->alias] = $this->_defaults;
		}
		$this->settings[$Model->alias] = array_merge($this->settings[$Model->alias], $settings);
	}
	
	public function PhpPdf_getHtml(Model $Model, $pdf_path = false)
	{
		$Model->modelError = false;
		
		// find if we have the command we need
		$command = new Command('which pdftohtml');
		
		$pdftohtml = false;
		if ($command->execute())
		{
			$pdftohtml = $command->getOutput();
		}
		else
		{
			$Model->modelError = $command->getError();
			$exitCode = $command->getExitCode();
			return false;
		}
		
		$pdftohtml_cmd = $pdftohtml. ' -stdout -xml "'.$pdf_path.'" -';
		
		$command = new Command($pdftohtml_cmd);
		$pdf_html = false;
		if ($command->execute())
		{
			return $command->getOutput();
		}
		else
		{
			$Model->modelError = $command->getError();
			$exitCode = $command->getExitCode();
			return false;
		}
	}
	
	public function PhpPdf_getText(Model $Model, $pdf_path = false)
	{
		$Model->modelError = false;
		
		// find if we have the command we need
		$command = new Command('which pdftotext');
		
		$pdftotext = false;
		if ($command->execute())
		{
			$pdftotext = $command->getOutput();
		}
		else
		{
			$Model->modelError = $command->getError();
			$exitCode = $command->getExitCode();
			return false;
		}
		
		$pdftotext_cmd = $pdftotext. ' "'.$pdf_path.'" -';
		
		$command = new Command($pdftotext_cmd);
		$pdf_text = false;
		if ($command->execute())
		{
			return $command->getOutput();
		}
		else
		{
			$Model->modelError = $command->getError();
			$exitCode = $command->getExitCode();
			return false;
		}
	}
	
	public function PhpPdf_getSignature(Model $Model, $pdf_path = false)
	{
		if(!$pdf_path)
			return false;
		$content = file_get_contents($pdf_path);
		$content = preg_split('/(\r|\n)/', $content);
		
		$sig = false;
		foreach($content as $line)
		{
			if(!preg_match('/Type\/Sig/', $line))
				continue;
			$sig = $line;
			break;
		}
		unset($content);
		
		if(!$sig)
		{
			$Model->modelError = __('This PDF Form is not signed.');
			return false;
		}
		// get the name
		$sig = explode('/', $sig);
		
		$out = array('name' => false, 'date' => false);
		foreach($sig as $line)
		{
			// the date
			$matches = array();
			if(preg_match('/M\(D\:(\d+)/', $line, $matches) and isset($matches[1]))
			{
				$out['date'] = date('Y-m-d H:i:s', strtotime($matches[1]));
			}
			
			$matches = array();
			if(preg_match('/^Name\((.*)\)$/', $line, $matches) and isset($matches[1]))
			{
				$out['name'] = str_replace('\\', '', $matches[1]);
			}
		}
		return $out;
	}
	
	public function PhpPdf_getFormData(Model $Model, $pdf_path = false, $out_part = false)
	{
		$pdf = new Pdf($pdf_path);
		$data = $pdf->getDataFields();
		
		$out = array('short' => array(), 'long' => array(), 'raw' => $data);
		$data = explode("\n---\n", $data);
		
		foreach($data as $field)
		{
			$this_field_key = false;
			$this_field_value = false;
			$this_field_values = array();
			$field_parts = explode("\n", $field);
			foreach($field_parts as $field_detail)
			{
				if(strpos($field_detail, ':') === false)
					continue;
					
				$field_detail_parts = explode(':', $field_detail);
				$field_key = array_shift($field_detail_parts);
				$field_value = trim(implode(':', $field_detail_parts));
				if($field_key == 'FieldName')
				{
					$this_field_key = strtolower(Inflector::slug($field_value));
				}
				if($field_key == 'FieldValue')
				{
					$this_field_value = $field_value;
				}
				if($field_key == 'FieldType')
				{
					$field_value = strtolower(Inflector::slug($field_value));
				}
				$this_field_values[$field_key] = $field_value;
			}
			if($this_field_key)
			{
				$out['short'][$this_field_key] = $this_field_value;
				$out['long'][$this_field_key] = $this_field_values;
			}
		}
		
		if($out_part and isset($out[$out_part]))
			return $out[$out_part];
		
		return $out;
	}
	
	public function PhpPdf_getPdfForm(Model $Model, $id = false, $object = false, $options = array())
	{
		$Model->modelError = false;
	 	
	 	if(!$id) 
	 	{
	 		$Model->modelError = __('Unknown ID');
	 		return false;
	 	}
	 	
	 	if(!$object) 
	 	{
	 		$Model->modelError = __('Unknown Objectf');
	 		return false;
	 	}
	 	
	 	if(!isset($options['pdf_template']))
	 	{
	 		$Model->modelError = __('Unknown Pdf Template 1');
	 		return false;
	 	}
	 	
	 	if(!trim($options['pdf_template']))
	 	{
	 		$Model->modelError = __('Unknown Pdf Template 2');
	 		return false;
	 	}
	 	
	 	// path to the template
	 	$pdf_form_template = APP. 'View'. DS. $Model->name. DS. 'pdf'. DS. $options['pdf_template'];
	 	
	 	if(!file_exists($pdf_form_template))
	 	{
	 		$Model->modelError = __('Unknown Pdf Template 3');
	 		return false;
	 	}
	 	
	 	if(!is_readable($pdf_form_template))
	 	{
	 		$Model->modelError = __('Unknown Pdf Template 4');
	 		return false;
	 	}
	 	
	 	if(!isset($options['pdf_filename']))
	 	{
	 		$Model->modelError = __('Unknown Pdf File Name 1');
	 		return false;
	 	}
	 	
	 	if(!trim($options['pdf_filename']))
	 	{
	 		$Model->modelError = __('Unknown Pdf File Name 2');
	 		return false;
	 	}
	 	
	 	if(!isset($options['field_map']))
	 	{
	 		$Model->modelError = __('Unknown field map 1');
	 		return false;
	 	}
	 	
	 	if(!is_array($options['field_map']))
	 	{
	 		$Model->modelError = __('Unknown field map 2');
	 		return false;
	 	}
	 	$object = Hash::flatten($object);
	 	
	 	$formData = array();
	 	foreach($options['field_map'] as $pdf_field => $object_field)
	 	{
	 		$field_value = false;
	 		$object_field_options = array();
	 		if(is_array($object_field))
	 		{
	 			if(isset($object_field['value']))
	 			{
	 				$value = $object_field['value'];
	 			}
	 			else
	 			{
	 				$_object_field = 'dontexist';
	 				if(isset($object_field['field']))
	 				{
	 					$_object_field = $object_field['field'];
	 				}
	 				
	 				$value = (isset($object[$_object_field])?$object[$_object_field]:false);
	 				if($value and isset($object_field['type']) and isset($object_field['format']))
	 				{
	 					if($object_field['type'] == 'date')
	 					{
	 						$value = date($object_field['format'], strtotime($value));
	 					}
	 				}
	 			}
	 		}
	 		else
	 		{
		 		$value = (isset($object[$object_field])?$object[$object_field]:false);	
	 		}
	 		
	 		$formData[$pdf_field] = $value;
	 	}
	 	
	 	$pdf_filename_filled = $options['pdf_filename'];
	 	$pdf_filename_combined = $pdf_filename_filled;
		
		$fileparts = explode('.', $pdf_filename_filled);
		$pdf_filename_extension = array_pop($fileparts);
		$pdf_filename_name = implode('.', $fileparts). '-filled';
		$pdf_filename_filled = $pdf_filename_name. '.'. $pdf_filename_extension;
		
		if(!$paths = $Model->paths($id, true, $pdf_filename_filled))
		{
	 		$Model->modelError = __('Unknown pdf file path.');
	 		return false;
		}
		
		$pdf = new Pdf($pdf_form_template);
		if(!$pdf
			->needAppearances()
			->allow('AllFeatures')
			->fillForm($formData)
			->saveAs($paths['sys']))
		{
	 		$Model->modelError = __('Unable to create pdf file: %s', $pdf->getError());
	 		return false;
		}
		
		// move it to a '-filled'
		$pdf_filename_filled = $paths['sys'];
		if(!$paths = $Model->paths($id, true, $pdf_filename_combined))
		{
	 		$Model->modelError = __('Unknown pdf file path.');
	 		return false;
		}
		
		// combine the original, and filled as a new pdf
		$pdf = new Pdf(array(
			'A' => $pdf_filename_filled,
			'B' => $pdf_form_template
		));
		$pdf->saveAs($paths['sys']);
		
		$pdf = new Pdf($paths['sys']);
		$pdf->cat(1)
			->saveAs($paths['sys']);
		
		$paths = $Model->paths($id);
		
		$params = array(
			'id' => $pdf_filename_combined,
			'download' => true,
			'path' => $paths['sys'],
		);
		
		if(stripos($options['pdf_filename'], '.') !== false)
		{
			$fileparts = explode('.', $options['pdf_filename']);
			$params['extension'] = array_pop($fileparts);
			$params['name'] = implode('.', $fileparts);
		}
		
		return $params;
	}
	
	public function PhpPdf_mergePdfFiles(Model $Model, $files = array(), $merged_filename = false)
	{
	 	if(!$merged_filename) 
	 	{
	 		$Model->modelError = __('Unknown Merged Filename');
	 		return false;
	 	}
	 	
		$paths = array();
		foreach($files as $id => $filename)
		{
			if(!$this_paths = $Model->paths($id, true, $filename))
			{
				continue;
	 		}
	 		if(!is_readable($this_paths['sys']))
	 		{
	 			$Model->modelError = __('Unable to find the file: %s', $filename);
	 			return false;
	 		}
	 		$paths[] = $this_paths['sys'];
		}
		
		if(!$paths)
		{
	 		$Model->modelError = __('Unknown pdf file paths.');
	 		return false;
		}
		
		if(!preg_match('/.pdf$/', $merged_filename))
		{
			$merged_filename .= '.pdf';
		}
		
		$merge_path = TMP. $merged_filename;
		
		$pdf = new Pdf;
		foreach($paths as $path)
		{
			$pdf->addFile($path);
		}
		
		if(!$pdf->saveAs($merge_path))
		{
	 		$Model->modelError = $pdf->getError();
	 		return false;
		}
		
		$merged_filename_name = explode('.', $merged_filename);
		array_pop($merged_filename_name);
		$merged_filename_name = implode('.', $merged_filename_name);
		
		$params = array(
			'id' => basename($merged_filename),
			'download' => true,
			'path' => 'tmp'. DS,
			'extension' => 'pdf',
			'name' => $merged_filename_name,
		);
		return $params;
	}
}